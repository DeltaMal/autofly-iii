<?php

/*
 * ReconcilerTest.php
 * Copyright (c) 2026 the ext fork authors. Added for the ext fork of Firefly III
 * (https://github.com/DeltaMal/autofly-iii), based on Firefly III by james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Ext\Tests\Feature\Statements;

use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine;
use FireflyIII\Ext\Statements\Reconciler;
use FireflyIII\Ext\Statements\StatementImporter;
use FireflyIII\Ext\Statements\UploadOutcome;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The matcher scenarios of ext/docs/02 "Acceptance".
 *
 * @internal
 */
#[CoversClass(Reconciler::class)]
#[CoversClass(StatementImporter::class)]
#[CoversClass(TransactionWriter::class)]
final class ReconcilerTest extends FeatureTestCase
{
    use StatementTestHelpers;

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = $this->createUser();
        $this->actingAs($this->user);
        $this->account = $this->createAccount($this->user, 'FNB Cheque', 'asset', '6201 2345 678', 'EUR', ['opening_balance' => '1000', 'opening_balance_date' => \Carbon\Carbon::parse('2024-09-19', config('app.timezone'))]);
    }

    public function testReservationTwoDaysBeforePostingIsMatched(): void
    {
        $email   = $this->emailTransaction($this->user, $this->account, '2025-07-06', '-74.77', 'PnP God Fehulo', '1234');
        $outcome = $this->upload($this->user, self::layout([['2025-07-08', 'PURCH PnP God Fehulo 400000******1234', '-74.77']], '925.23'));

        self::assertSame(UploadOutcome::REVIEW, $outcome->kind);
        $line    = $outcome->statement->lines()->first();
        self::assertSame(StatementLine::MATCHED, $line->status);
        self::assertSame($email->transactionJournals()->first()->id, $line->transaction_journal_id);
        self::assertSame('0.00', $outcome->statement->summary['breakdown']['unexplained']);
    }

    public function testIdenticalSameDayAmountsAreMatchedOneToOneAndDeterministically(): void
    {
        $first  = $this->emailTransaction($this->user, $this->account, '2025-07-08', '-40.20', 'PICK N PAY', '1234')->transactionJournals()->first()->id;
        $second = $this->emailTransaction($this->user, $this->account, '2025-07-08', '-40.20', 'PICK N PAY', '1234')->transactionJournals()->first()->id;
        $text   = self::layout([['2025-07-08', 'PURCH PICK N PAY 400000******1234', '-40.20'], ['2025-07-08', 'PURCH PICK N PAY 400000******1234', '-40.20']], '919.60');

        $lines  = $this->upload($this->user, $text)->statement->lines()->get();
        self::assertSame([StatementLine::MATCHED, StatementLine::MATCHED], $lines->pluck('status')->all());
        // posting index 0 gets the lowest journal id
        self::assertSame([$first, $second], $lines->pluck('transaction_journal_id')->all());

        // the same analysis again gives the same assignment
        $again  = (new Reconciler($this->account))->analyse((new \FireflyIII\Ext\Banks\Fnb\TransactionHistoryPdfParser())->parse(\FireflyIII\Ext\Statements\StatementFile::fromText($text)));
        self::assertSame([$first, $second], array_map(static fn ($o) => $o->journalId, $again->lines));
    }

    public function testFuelPreAuthWithADifferentAmountIsAProposal(): void
    {
        $preAuth   = $this->emailTransaction($this->user, $this->account, '2025-06-26', '-500.00', 'ENGEN R89', '1234');
        $statement = $this->upload($this->user, self::layout([['2025-06-27', 'PURCH ENGEN R89 400000******1234', '-681.69']], '318.31'))->statement;
        $line      = $statement->lines()->first();

        self::assertSame(StatementLine::PROPOSAL, $line->status);
        self::assertSame(['journal_id' => $preAuth->transactionJournals()->first()->id, 'from' => '-500.00', 'to' => '-681.69', 'reason' => 'same card'], $line->proposal);
        self::assertNotNull(app(StatementImporter::class, ['user' => $this->user])->autoCommitBlocker($statement));

        // only applied on explicit confirmation
        (new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class)))->commit($statement, null, [$line->id]);
        $journal   = TransactionJournal::find($line->transaction_journal_id);
        self::assertSame('-681.69', bcadd((string) Transaction::query()->where('transaction_journal_id', $journal->id)->where('account_id', $this->account->id)->value('amount'), '0', 2));
        self::assertSame(StatementLine::MATCHED, $line->refresh()->status);
    }

    public function testReservationAndReversalNotOnTheStatementAreAReversedPair(): void
    {
        $this->emailTransaction($this->user, $this->account, '2025-06-28', '-767.98', 'Corner Deli', '7805');
        $this->emailTransaction($this->user, $this->account, '2025-06-30', '767.98', 'Card reversal', '7805');
        $this->emailTransaction($this->user, $this->account, '2025-07-14', '-50.00', 'Late reservation', '7805');
        $statement = $this->upload($this->user, self::layout([['2025-07-08', 'CREDITCARDSETTLE', '-100.00'], ['2025-06-19', 'MA BILUM', '-10.00']], '890.00'))->statement;

        $kinds     = array_column($statement->summary['firefly_only'], 'kind', 'description');
        self::assertSame(['Corner Deli' => 'reversed_pair', 'Card reversal' => 'reversed_pair', 'Late reservation' => 'pending'], $kinds);
        $breakdown = $statement->summary['breakdown'];
        self::assertSame('-50.00', $breakdown['pending']);
        self::assertSame('-110.00', $breakdown['missing']);
        self::assertSame('0.00', $breakdown['unexplained']);
    }

    public function testMonthlyFeeIsMissingThenCreatedWithTheStatementTag(): void
    {
        $statement = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20'))->statement;
        $line      = $statement->lines()->first();
        self::assertSame(StatementLine::MISSING, $line->status);

        $statement = (new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class)))->commit($statement);
        $line->refresh();
        self::assertSame(StatementLine::CREATED, $line->status);
        $journal   = TransactionJournal::with('tags', 'transactionType')->find($line->transaction_journal_id);
        self::assertSame('Withdrawal', $journal->transactionType->type);
        self::assertSame('#MONTHLY ACCOUNT FEE', $journal->description);
        $tags      = $journal->tags->pluck('tag')->all();
        self::assertContains('statement-import', $tags);
        self::assertContains('fnb', $tags);
        self::assertContains('Statement import '.today(config('app.timezone'))->format('Y-m-d'), $tags);
        self::assertSame('stmt-'.substr($line->line_hash, 0, 32), $journal->transactionJournalMeta()->where('name', 'external_id')->first()?->data);
        self::assertTrue((bool) Transaction::query()->where('transaction_journal_id', $journal->id)->where('account_id', $this->account->id)->value('reconciled'));
        self::assertSame(Statement::STATUS_COMMITTED, $statement->status);
        self::assertSame('0.00', $statement->summary['breakdown']['unexplained']);

        // committing again changes nothing
        $count     = TransactionJournal::count();
        (new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class)))->commit($statement);
        self::assertSame($count, TransactionJournal::count());
    }

    public function testReUploadingAnOverlappingStatementCreatesNothing(): void
    {
        $this->emailTransaction($this->user, $this->account, '2025-06-20', '-185.00', 'NEHUF', '1234');
        $first     = $this->upload($this->user, self::layout([
            ['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80'],
            ['2025-06-21', 'PURCH NEHUF 400000******1234', '-185.00'],
        ], '612.20', statementDate: '2025-06-28'))->statement;
        $importer  = new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class));
        $importer->commit($first);
        $count     = TransactionJournal::count();

        // a later statement that overlaps (fee and purchase again) plus one new line
        $second    = $this->upload($this->user, self::layout([
            ['2025-07-08', 'CREDITCARDSETTLE', '-12.20'],
            ['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80'],
            ['2025-06-21', 'PURCH NEHUF 400000******1234', '-185.00'],
        ], '600.00'))->statement;
        $statuses  = $second->lines()->pluck('status')->all();
        self::assertSame([StatementLine::MISSING, StatementLine::MATCHED, StatementLine::MATCHED], $statuses);
        $importer->commit($second);
        self::assertSame($count + 1, TransactionJournal::count(), 'only the new line is created');

        // the exact same file again is refused, and with re-process nothing new is created either
        $text      = self::layout([['2025-07-08', 'CREDITCARDSETTLE', '-12.20'], ['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80'], ['2025-06-21', 'PURCH NEHUF 400000******1234', '-185.00']], '600.00');
        self::assertSame(UploadOutcome::DUPLICATE, $this->upload($this->user, $text)->kind);
        $third     = $this->upload($this->user, $text, reprocess: true)->statement;
        self::assertSame([StatementLine::MATCHED, StatementLine::MATCHED, StatementLine::MATCHED], $third->lines()->pluck('status')->all());
        $importer->commit($third);
        self::assertSame($count + 1, TransactionJournal::count());
    }

    public function testDeletedTransactionsAreNeverRecreated(): void
    {
        $statement = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20'))->statement;
        $importer  = new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class));
        $importer->commit($statement);
        $journal   = TransactionJournal::find($statement->lines()->first()->transaction_journal_id);
        $journal->transactionGroup->delete();
        $journal->delete();
        $count     = TransactionJournal::withTrashed()->count();

        // a different statement with the same line: it is "missing" again, but commit refuses to recreate it
        $other     = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20', statementDate: '2025-07-15'))->statement;
        $importer->commit($other);
        self::assertSame($count, TransactionJournal::withTrashed()->count());
    }

    public function testAccountIsDetectedFromTheStatementAndAmbiguityAsksTheUser(): void
    {
        $text    = self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20');
        self::assertSame($this->account->id, $this->upload($this->user, $text)->statement->account_id);

        $this->createAccount($this->user, 'Same number', 'asset', '62012345678');
        $outcome = $this->upload($this->user, self::layout([['2025-06-27', 'X', '-1.00']], '1.00'));
        self::assertSame(UploadOutcome::NEEDS_ACCOUNT, $outcome->kind);
        self::assertStringContainsString('several accounts', (string) $outcome->notice);
    }

    public function testAutoCommitOnlyWhenSafe(): void
    {
        $committed = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20'), options: ['auto_commit' => true]);
        self::assertSame(UploadOutcome::COMMITTED, $committed->kind);

        $broken    = str_replace('-1.00 DR', '-2.00 DR', self::layout([['2025-06-27', 'A', '-1.00'], ['2025-06-26', 'B', '-202.80']], '700.00'));
        $review    = $this->upload($this->user, $broken, options: ['auto_commit' => true]);
        self::assertSame(UploadOutcome::REVIEW, $review->kind);
        self::assertNotNull($review->notice);
    }

    public function testAdjustmentBooksTheUnexplainedDifference(): void
    {
        // Firefly has 1000, the bank says 1100 after one fee: 300 is unexplained
        $statement = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-200.00']], '1100.00'), options: ['adjust' => true])->statement;
        self::assertSame('300.00', $statement->summary['breakdown']['unexplained']);
        $statement = (new StatementImporter($this->user, app(\FireflyIII\Ext\Banks\BankRegistry::class)))->commit($statement);

        self::assertNotNull($statement->summary['adjustment_group']);
        self::assertSame('0.00', $statement->summary['breakdown']['unexplained']);
        $journal   = TransactionJournal::with('transactionType')->where('transaction_group_id', $statement->summary['adjustment_group'])->first();
        self::assertSame('Reconciliation', $journal->transactionType->type);
    }
}
