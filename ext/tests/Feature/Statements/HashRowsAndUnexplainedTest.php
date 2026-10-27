<?php

/*
 * HashRowsAndUnexplainedTest.php
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

use Carbon\Carbon;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Http\Controllers\StatementController;
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine;
use FireflyIII\Ext\Statements\Reconciler;
use FireflyIII\Ext\Statements\StatementImporter;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Account;
use FireflyIII\Models\Note;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "#" rows (handled like every other line), the unexplained difference and proposal percentages (ext/docs/02).
 * All values are synthetic.
 *
 * @internal
 */
#[CoversClass(Reconciler::class)]
#[CoversClass(StatementImporter::class)]
#[CoversClass(StatementController::class)]
final class HashRowsAndUnexplainedTest extends FeatureTestCase
{
    use StatementTestHelpers;

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user    = $this->createUser();
        $this->actingAs($this->user);
        $this->account = $this->createAccount($this->user, 'Synthetic Cheque', 'asset', '62012345678', 'EUR', ['opening_balance' => '1000', 'opening_balance_date' => Carbon::parse('2024-09-19', config('app.timezone'))]);
    }

    public function testAHashRowWithoutAMatchingJournalIsImportedAsMissingLikeAnyOtherLine(): void
    {
        $statement = $this->upload($this->user, self::layout([['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80']], '797.20'))->statement;
        $line      = $statement->lines()->first();
        self::assertSame(StatementLine::MISSING, $line->status);
        self::assertNull($line->proposal);

        // counterparty and description come from the statement text, as for any other line
        (new StatementImporter($this->user, app(BankRegistry::class)))->commit($statement);
        $journal   = TransactionJournal::find($line->refresh()->transaction_journal_id);
        self::assertSame(StatementLine::CREATED, $line->status);
        self::assertSame('#MONTHLY ACCOUNT FEE', $journal->description);
        self::assertSame('#MONTHLY ACCOUNT FEE', $this->opposingName($journal));
        self::assertSame('Expense account', Transaction::query()->where('transaction_journal_id', $journal->id)->where('amount', '>', 0)->first()->account->accountType->type);
        self::assertSame(0, Account::query()->where('user_group_id', $this->user->user_group_id)->where('name', 'FNB')->count());
    }

    public function testAHashRowMatchesAnExistingJournalWithTheSameAmount(): void
    {
        // e.g. interest or a payment that failed to come in by email but was booked by hand
        $journal   = $this->emailTransaction($this->user, $this->account, '2025-06-25', '-6.50', 'Coffee Corner', '1234')->transactionJournals()->first()->id;
        $statement = $this->upload($this->user, self::layout([['2025-06-26', '#SERVICE FEE', '-6.50']], '993.50'))->statement;
        $line      = $statement->lines()->first();
        self::assertSame(StatementLine::MATCHED, $line->status);
        self::assertSame($journal, $line->transaction_journal_id);
        self::assertSame([], $statement->summary['firefly_only']);
    }

    public function testHashRowsUseTheSameScoringAndOneToOneMatching(): void
    {
        // one card transaction, two statement lines with its amount: the card line scores higher (card ref, tokens)
        $card      = $this->emailTransaction($this->user, $this->account, '2025-06-26', '-202.80', 'Corner Deli', '1234')->transactionJournals()->first()->id;
        $statement = $this->upload($this->user, self::layout([
            ['2025-06-26', '#MONTHLY ACCOUNT FEE', '-202.80'],
            ['2025-06-26', 'PURCH CORNER DELI 400000******1234', '-202.80'],
        ], '594.40'))->statement;
        $lines     = $statement->lines()->orderBy('posting_index')->get();

        self::assertSame(StatementLine::MISSING, $lines[0]->status);
        self::assertSame(StatementLine::MATCHED, $lines[1]->status);
        self::assertSame($card, $lines[1]->transaction_journal_id);
    }

    public function testAHashRowCanBeAProposal(): void
    {
        $journal   = $this->emailTransaction($this->user, $this->account, '2025-07-02', '-3.00', 'International Txn Fee')->transactionJournals()->first()->id;
        $statement = $this->upload($this->user, self::layout([['2025-07-02', '#INTERNATIONAL TXN FEE', '-3.15']], '996.85'))->statement;
        $line      = $statement->lines()->first();
        self::assertSame(StatementLine::PROPOSAL, $line->status);
        self::assertSame($journal, $line->transaction_journal_id);
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertSee('+5.0% difference');
    }

    public function testTheReviewPageListsTheUnexplainedItemsAndAsks(): void
    {
        // Firefly: 1000 - 50 (a card purchase the bank never posted) = 950. The statement: -100 and -10 -> 890.
        $mystery   = $this->emailTransaction($this->user, $this->account, '2025-06-20', '-50.00', 'Mystery Shop', '1234');
        $statement = $this->upload($this->user, self::layout([['2025-07-08', 'CREDITCARDSETTLE', '-100.00'], ['2025-06-19', 'MA BILUM', '-10.00']], '890.00'))->statement;
        self::assertSame('50.00', $statement->summary['breakdown']['unexplained']);

        $html      = $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->getContent();
        self::assertStringContainsString('id="ext-unexplained"', $html);
        self::assertStringContainsString('Only in Firefly (unexplained)', $html);
        self::assertStringContainsString(route('transactions.edit', [$mystery->id]), $html);
        self::assertStringContainsString('Book 50.00 as Unexplained?', $html);
        self::assertStringContainsString('name="book_unexplained"', $html);
        self::assertStringNotContainsString('Book an adjustment', $html);

        // the upload form has no silent adjustment option any more
        $this->get(route('ext.statements.create'))->assertOk()->assertDontSee('options[adjust]', false);

        // commit, answering no: nothing is booked, and the page keeps offering it
        $lines     = $statement->lines()->pluck('id')->all();
        $this->post(route('ext.statements.commit', [$statement->id]), ['create' => $lines, 'book_unexplained' => 'no'])->assertRedirect();
        $statement->refresh();
        self::assertSame(Statement::STATUS_COMMITTED, $statement->status);
        self::assertNull($statement->summary['adjustment_group']);
        self::assertSame('no', $statement->summary['unexplained']['decision']);
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()
            ->assertSee('Book 50.00 as Unexplained?')->assertSee('You chose not to book it')->assertSee(route('ext.statements.unexplained', [$statement->id]), false);

        // an amount that is no longer the remaining one books nothing
        $this->post(route('ext.statements.unexplained', [$statement->id]), ['answer' => 'yes', 'amount' => '49.00'])->assertSessionHas('warning');
        self::assertNull($statement->refresh()->summary['adjustment_group']);

        // yes: a reconciliation transaction with category Unexplained and the items in its notes
        $this->post(route('ext.statements.unexplained', [$statement->id]), ['answer' => 'yes', 'amount' => '50.00'])->assertSessionHas('success');
        $statement->refresh();
        $journal   = TransactionJournal::with('transactionType', 'categories')->where('transaction_group_id', $statement->summary['adjustment_group'])->firstOrFail();
        self::assertSame('Reconciliation', $journal->transactionType->type);
        self::assertSame(['Unexplained'], $journal->categories->pluck('name')->all());
        $notes     = (string) Note::query()->where('noteable_type', TransactionJournal::class)->where('noteable_id', $journal->id)->value('text');
        self::assertStringContainsString('Only in Firefly: 2025-06-20 Mystery Shop -50.00', $notes);
        self::assertStringContainsString('50.00 (bank minus expected Firefly)', $notes);
        self::assertSame('0.00', $statement->summary['breakdown']['unexplained']);
        // Firefly now matches the bank
        self::assertSame('890.00', (new Reconciler($this->account))->fireflyBalance(Carbon::parse('2025-07-14', config('app.timezone'))));
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertDontSee('as Unexplained?');
    }

    public function testAnswerYesAtCommitBooksOnlyWhenTheAmountIsUnchanged(): void
    {
        $this->emailTransaction($this->user, $this->account, '2025-06-20', '-50.00', 'Mystery Shop', '1234');
        $statement = $this->upload($this->user, self::layout([['2025-07-08', 'CREDITCARDSETTLE', '-100.00'], ['2025-06-19', 'MA BILUM', '-10.00']], '890.00'))->statement;
        $lines     = $statement->lines()->orderBy('posting_index')->get();

        // ignoring MA BILUM moves its -10 into the unexplained amount, so the 50.00 the user saw is not booked
        $this->post(route('ext.statements.commit', [$statement->id]), ['create' => [$lines[0]->id], 'ignore' => [$lines[1]->id], 'book_unexplained' => '50.00'])
            ->assertSessionHas('warning');
        $statement->refresh();
        self::assertNull($statement->summary['adjustment_group']);
        $unexplained = (new StatementImporter($this->user, app(BankRegistry::class)))->unexplained($statement);
        self::assertSame('40.00', $unexplained['remaining']);
        self::assertSame(['firefly_only', 'ignored'], array_column($unexplained['items'], 'kind'));
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertSee('Statement line ignored')->assertSee('Book 40.00 as Unexplained?');
    }

    public function testUnimportedLinesAndRefusedProposalsAreListedAfterCommit(): void
    {
        $this->emailTransaction($this->user, $this->account, '2025-06-26', '-500.00', 'ENGEN R89', '1234');
        $statement = $this->upload($this->user, self::layout([['2025-07-08', 'CREDITCARDSETTLE', '-100.00'], ['2025-06-27', 'PURCH ENGEN R89 400000******1234', '-681.69']], '218.31'))->statement;

        // the proposal shows its difference in percent (181.69 of 500.00)
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()->assertSee('+36.3% difference');

        $this->post(route('ext.statements.commit', [$statement->id]), ['create' => []])->assertRedirect();
        $unexplained = (new StatementImporter($this->user, app(BankRegistry::class)))->unexplained($statement->refresh());
        self::assertSame(['not_imported', 'proposal'], array_column($unexplained['items'], 'kind'));
        self::assertSame('0.00', $unexplained['remaining']);
        $this->get(route('ext.statements.show', [$statement->id]))->assertOk()
            ->assertSee('Statement line not imported')->assertSee('Proposal not accepted')->assertDontSee('as Unexplained?');
    }

    public function testPercentDifference(): void
    {
        self::assertSame('+36.3', Reconciler::percentDifference('-500.00', '-681.69'));
        self::assertSame('-10.0', Reconciler::percentDifference('-500.00', '-450.00'));
        self::assertSame('+0.1', Reconciler::percentDifference('100.00', '100.05'));
        self::assertSame('-0.1', Reconciler::percentDifference('100.00', '99.95'));
        self::assertSame('+250.0', Reconciler::percentDifference('20.00', '70.00'));
        self::assertNull(Reconciler::percentDifference('0.00', '5.00'));
    }

    private function opposingName(TransactionJournal $journal): string
    {
        return (string) Transaction::query()->where('transaction_journal_id', $journal->id)->where('account_id', '!=', $this->account->id)->first()?->account?->name;
    }
}
