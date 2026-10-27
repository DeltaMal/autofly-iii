<?php

/*
 * MailImporterTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Mail;

use Carbon\Carbon;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\MailProvider;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Models\MailMessage as MailMessageModel;
use FireflyIII\Ext\Models\MailTransaction;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The pipeline behaviour locked in by the Python tests (ext/docs/06 section 8), with a fake provider.
 *
 * @internal
 */
#[CoversClass(MailImporter::class)]
final class MailImporterTest extends FeatureTestCase
{
    private User $user;
    private Account $card;

    /** @var array<int, FakeMailProvider> mailbox id => provider */
    private array $providers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'UTC'));
        $this->user = $this->createUser();
        $this->actingAs($this->user);
        $this->card = $this->createAccount($this->user, 'FNB card', 'asset', '110149 0000', 'ZAR');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testImportsLabelsAndIsIdempotent(): void
    {
        [$mailbox, $provider] = $this->mailbox('personal', ['m1' => $this->golden('eml:reserved_purchase.eml'), 'm2' => $this->golden('atm_is_unmatched'), 'm3' => $this->golden('electricity_token_ignored')]);

        $summary = $this->importer()->run($mailbox);
        self::assertNull($summary->error);
        self::assertSame(1, $summary->transactions['created']);
        self::assertStringContainsString('from:inContact@fnb.co.za after:'.Carbon::now()->subDays(30)->getTimestamp().' -label:firefly-imported', $provider->queries[0]);

        $journal = TransactionJournal::with('transactionType', 'tags')->firstOrFail();
        self::assertSame('Withdrawal', $journal->transactionType->type);
        self::assertSame('Corner Deli', $journal->description);
        self::assertSame('2026-10-04 12:56', $journal->date->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        self::assertSame('fnb-2b76db0f0c6a49e71a1d3e7a4ad141de', $journal->transactionJournalMeta()->where('name', 'external_id')->first()->data);
        self::assertSame('card..7805', $journal->transactionJournalMeta()->where('name', 'internal_reference')->first()->data);
        self::assertSame(
            'FNB :-) R767.98 reserved for purchase @ Corner Deli from FNB card a/c..110149 using card..7805. Avail R88884. 4Oct 12:56 | Email: FNB :-) R767.98 reserved for purchase @ Corner Deli from FNB card a/c..110149 using card..7805. 4Oct 12:56 | Received: 2026-10-04T12:59:31+02:00 | Message: personal/reserved_purchase',
            $journal->notes()->first()->text
        );
        self::assertContains('fnb-reserved', $journal->tags->pluck('tag')->all());
        self::assertContains('Email import 2026-10-27', $journal->tags->pluck('tag')->all());

        // statuses and labels
        $statuses = MailMessageModel::query()->pluck('status', 'provider_message_id')->all();
        // m2 carries the same reservation as m1 (same external id) plus an ATM line: it is unmatched
        self::assertSame(['m1' => 'imported', 'm2' => 'unmatched', 'm3' => 'ignored'], $statuses);
        self::assertSame(['Firefly/Imported'], $provider->labelsOf('m1'));
        self::assertSame(['Firefly/Unmatched'], $provider->labelsOf('m2'));
        self::assertSame([], $provider->labelsOf('m3'));
        self::assertNotNull(MailMessageModel::query()->where('provider_message_id', 'm2')->first()->raw);
        self::assertStringContainsString('withdrawn from cheq', (string) MailMessageModel::query()->where('provider_message_id', 'm2')->first()->unmatched_text);

        // second run: nothing new, final emails are not fetched again (unmatched ones are)
        $count   = TransactionJournal::count();
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame($count, TransactionJournal::count());
        self::assertSame(0, $summary->transactions['created']);
        self::assertSame(1, $provider->fetches['m1']);
        self::assertSame(1, $provider->fetches['m3']);
        self::assertSame(2, $provider->fetches['m2']);
        self::assertNotNull($mailbox->refresh()->last_polled_at);
        self::assertNull($mailbox->last_error);
    }

    public function testUnmappedWaitsAndImportsOnceMapped(): void
    {
        [$mailbox] = $this->mailbox('personal', ['m1' => $this->golden('paid_from_ref')]); // cheq ..379711: no such account
        $this->importer()->run($mailbox);
        $tx        = MailTransaction::query()->firstOrFail();
        self::assertSame(MailTransaction::UNMAPPED, $tx->status);
        self::assertStringContainsString('starts with 379711', (string) $tx->error);
        self::assertSame('pending', MailMessageModel::query()->first()->status);

        // a mapping to an account that does not exist stays unmapped
        $expense   = $this->createAccount($this->user, 'Not an asset', 'expense');
        AccountIdentifier::create(['user_group_id' => $this->user->user_group_id, 'account_id' => $expense->id, 'bank' => 'fnb', 'kind' => 'account_number', 'value' => '379711']);
        $this->importer()->run($mailbox->refresh());
        self::assertSame(MailTransaction::UNMAPPED, $tx->refresh()->status);

        AccountIdentifier::query()->update(['account_id' => $this->card->id]);
        $this->importer()->run($mailbox->refresh());
        self::assertSame(MailTransaction::IMPORTED, $tx->refresh()->status);
        self::assertSame('imported', MailMessageModel::query()->first()->status);
        self::assertSame(1, TransactionJournal::query()->where('description', 'Electricity 123')->count());
    }

    public function testExistingExternalIdsAreNotCreatedAgainEvenWhenDeleted(): void
    {
        [$mailbox] = $this->mailbox('personal', ['m1' => $this->golden('thousands_commas')]);
        $this->importer()->run($mailbox);
        $journal   = TransactionJournal::firstOrFail();
        $journal->delete();

        // the same alert in a new email (e.g. re-sent): the deleted transaction never comes back
        [$other]   = $this->mailbox('second', ['x1' => $this->golden('thousands_commas', 'x1')]);
        MailTransaction::query()->delete();
        $summary   = $this->importer()->run($other);
        self::assertSame(1, $summary->transactions['already_in_firefly']);
        self::assertSame(0, TransactionJournal::query()->count());
        self::assertSame(MailTransaction::IMPORTED, MailTransaction::query()->firstOrFail()->status);
    }

    public function testSameAlertInTwoMailboxesIsImportedOnceAndBothAreLabelled(): void
    {
        [$a, $pa] = $this->mailbox('personal', ['m1' => $this->golden('eml:reserved_purchase.eml')]);
        [$b, $pb] = $this->mailbox('shared', ['s1' => $this->golden('eml:reserved_purchase.eml', 's1', 'shared')]);

        $this->importer()->run($a);
        $this->importer()->run($b);
        self::assertSame(1, TransactionJournal::count());
        self::assertSame(1, MailTransaction::count());
        self::assertSame(2, MailTransaction::first()->messages()->count());
        self::assertSame(['Firefly/Imported'], $pa->labelsOf('m1'));
        self::assertSame(['Firefly/Imported'], $pb->labelsOf('s1'));
    }

    public function testTransferMatrix(): void
    {
        $savings = $this->createAccount($this->user, 'Savings', 'asset', '432588', 'ZAR');
        $fusion  = $this->createAccount($this->user, 'Fusion', 'asset', '896016', 'ZAR');
        [$mailbox] = $this->mailbox('personal', [
            'own'  => $this->golden('transfer_own_accounts'),   // 432588 -> 896016: both tracked
            'loan' => $this->golden('transfer_to_home_loan'),   // 896016 -> 069063: target not tracked
        ]);
        $this->importer()->run($mailbox);
        $types   = TransactionJournal::with('transactionType')->get()->mapWithKeys(static fn ($j) => [$j->description => $j->transactionType->type])->all();
        self::assertSame('Transfer', $types['Transfer from Savings Account ..432588 to Fusion Private ..896016']);
        self::assertSame('Withdrawal', $types['Transfer from Fusion Private ..896016 to home loan ..069063']);

        // source not tracked, target tracked: a deposit on the target, named after the source label
        $savings->delete();
        MailTransaction::query()->delete();
        TransactionJournal::query()->forceDelete();
        [$again] = $this->mailbox('third', ['own2' => $this->golden('transfer_own_accounts', 'own2', 'third')]);
        $this->importer()->run($again);
        $journal = TransactionJournal::with('transactionType', 'transactions.account')->firstOrFail();
        self::assertSame('Deposit', $journal->transactionType->type);
        self::assertContains('Savings Account ..432588', $journal->transactions->pluck('account.name')->all());
        unset($fusion);
    }

    public function testShadowModeWritesNothingAndSetsNoLabelsThenGoesLive(): void
    {
        [$mailbox, $provider] = $this->mailbox('personal', ['m1' => $this->golden('eml:reserved_purchase.eml'), 'm2' => $this->golden('reserved_no_merchant')], Mailbox::SHADOW);
        $this->createAccount($this->user, 'Other card', 'asset', '922630', 'ZAR');
        // m2 already exists in Firefly (imported earlier by the Python importer)
        (new \FireflyIII\Ext\Import\TransactionWriter($this->user, $this->user->userGroup, 'Email import'))->store(new \FireflyIII\Ext\Import\TransactionRow($this->card, 'out', '519.35', Carbon::now(), 'Card purchase', 'Card purchase', externalId: 'fnb-02562b28e4a4ec1f9c917549f98001fa'));
        $count   = TransactionJournal::count();

        $summary = $this->importer()->run($mailbox);
        self::assertSame($count, TransactionJournal::count());
        self::assertSame([], $provider->labelCalls);
        self::assertSame(1, $summary->transactions['shadow_exists']);
        self::assertSame(1, $summary->transactions['shadow_would_create']);
        self::assertSame(['m1' => 'shadow_would_create', 'm2' => 'shadow_exists'], MailMessageModel::query()->pluck('status', 'provider_message_id')->all());

        // going live deletes the shadow rows; the next run imports m1 and dedups m2
        MailImporter::leaveShadowMode($mailbox);
        $mailbox->update(['mode' => Mailbox::LIVE]);
        self::assertSame(0, MailMessageModel::count());
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame(1, $summary->transactions['created']);
        self::assertSame(1, $summary->transactions['already_in_firefly']);
        self::assertSame(['Firefly/Imported'], $provider->labelsOf('m1'));
        self::assertSame(['Firefly/Imported'], $provider->labelsOf('m2'));
    }

    public function testDryRunSavesNothing(): void
    {
        [$mailbox, $provider] = $this->mailbox('personal', ['m1' => $this->golden('eml:reserved_purchase.eml')]);
        $summary = $this->importer()->run($mailbox, dryRun: true);
        self::assertSame(1, $summary->transactions['shadow_would_create']);
        self::assertSame(0, MailMessageModel::count());
        self::assertSame(0, MailTransaction::count());
        self::assertSame(0, TransactionJournal::count());
        self::assertSame([], $provider->labelCalls);
        self::assertNull($mailbox->refresh()->last_polled_at);
    }

    public function testFailuresRetryAndABrokenMailboxDoesNotStopTheOthers(): void
    {
        [$broken, $bp] = $this->mailbox('broken', ['b1' => $this->golden('eml:reserved_purchase.eml', 'b1', 'broken')]);
        [$fine]        = $this->mailbox('fine', ['f1' => $this->golden('eml:reserved_purchase.eml', 'f1', 'fine')]);
        $bp->broken    = true;

        $this->app->instance(MailImporter::class, $this->importer());
        $this->artisan('ext:mail:poll')->assertFailed();
        self::assertStringStartsWith(Mailbox::RECONNECT_REQUIRED, (string) $broken->refresh()->last_error);
        self::assertSame(1, TransactionJournal::count());
        self::assertNull($fine->refresh()->last_error);

        // a transaction that keeps failing becomes failed after 3 attempts, and --retry-failed resets it
        $tx = MailTransaction::firstOrFail();
        $tx->update(['status' => MailTransaction::FAILED, 'attempts' => 3]);
        $this->importer()->run($fine, retryFailed: true);
        self::assertSame(MailTransaction::IMPORTED, $tx->refresh()->status);
    }

    private function importer(): MailImporter
    {
        return new MailImporter(app(BankRegistry::class), fn (Mailbox $m): MailProvider => $this->providers[$m->id]);
    }

    /**
     * @param array<string, MailMessage> $messages
     *
     * @return array{0: Mailbox, 1: FakeMailProvider}
     */
    private function mailbox(string $name, array $messages, string $mode = Mailbox::LIVE): array
    {
        $mailbox                       = Mailbox::create([
            'user_group_id' => $this->user->user_group_id, 'user_id' => $this->user->id, 'name' => $name, 'provider' => 'gmail', 'enabled' => true, 'mode' => $mode,
            'query' => '', 'lookback_days' => 30, 'banks' => ['fnb'], 'oauth' => null,
        ]);
        $this->providers[$mailbox->id] = new FakeMailProvider($messages);

        return [$mailbox, $this->providers[$mailbox->id]];
    }

    private function golden(string $name, ?string $messageId = null, ?string $mailboxId = null): MailMessage
    {
        static $golden = null;
        $golden ??= array_column(json_decode((string) file_get_contents(__DIR__.'/../../fixtures/fnb-email/golden.json'), true)['messages'], null, 'name');
        $m        = $golden[$name]['message'];

        return new MailMessage($mailboxId ?? $m['mailbox_id'], $messageId ?? $m['message_id'], Carbon::parse($m['received_at']), $m['sender'], $m['subject'], $m['auth_results'], $m['bodies']);
    }
}
