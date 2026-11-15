<?php

/*
 * PaymentPairingTest.php
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
use FireflyIII\Ext\Banks\Fnb\FnbEmailTemplate;
use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\MailProvider;
use FireflyIII\Ext\Mail\PaymentPairing;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Models\MailMessage as MailMessageModel;
use FireflyIII\Ext\Models\MailTransaction;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * "R… paid to <account> a/c..NNNN. <when>" (rule payment_received) and its pairing with the "paid from" alert of
 * the paying account. All numbers are made up.
 *
 * @internal
 */
#[CoversClass(PaymentPairing::class)]
#[CoversClass(MailImporter::class)]
#[CoversClass(FnbEmailTemplate::class)]
final class PaymentPairingTest extends FeatureTestCase
{
    private const string AUTH     = 'mx.google.com; dkim=pass header.i=@fnb.co.za header.s=s1; spf=pass smtp.mailfrom=inContact@fnb.co.za; dmarc=pass header.from=fnb.co.za';
    private const string PAYING   = 'R412.50 paid from Fusion Private a/c..379711 @ Eft. Avail R10000. Ref.Fnbcc Card 100200. 9Jun 22:13';
    private const string RECEIVED = 'R412.50 paid to FNB card a/c..110149. 9Jun 23:56';

    private User $user;
    private Account $cheque;
    private Account $card;
    private ?FakeMailProvider $provider = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-10 08:00:00', 'Africa/Johannesburg'));
        $this->user   = $this->createUser();
        $this->actingAs($this->user);
        $this->cheque = $this->createAccount($this->user, 'Cheque', 'asset', '379711', 'ZAR');
        $this->card   = $this->createAccount($this->user, 'Credit card', 'debt', '110149', 'ZAR', ['liability_type' => 'debt', 'liability_direction' => 'credit']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testParsesTheReceivingSide(): void
    {
        $result = (new FnbEmailTemplate('Africa/Johannesburg'))->parse($this->alert('r1', self::RECEIVED, '2026-06-09 23:57'));
        self::assertSame('transactions', $result->outcome());
        self::assertCount(1, $result->transactions);
        $parsed = $result->transactions[0];
        self::assertSame('payment_received', $parsed->rule);
        self::assertSame('deposit', $parsed->kind);
        self::assertSame('412.50', $parsed->amount);
        self::assertSame('110149', $parsed->accountRef);
        self::assertNull($parsed->counterAccountRef);
        self::assertNull($parsed->balanceAfter);
        self::assertSame('Payment received', $parsed->counterparty);
        self::assertSame('Payment into FNB card ..110149', $parsed->description);
        self::assertSame('2026-06-09 23:56', $parsed->occurredAt->format('Y-m-d H:i'));
        self::assertSame(['fnb-received'], $parsed->tags);
        self::assertStringStartsWith('fnb-', $parsed->externalId);

        // the "paid to … @ channel" alerts keep their own rule
        $other = (new FnbEmailTemplate('Africa/Johannesburg'))->parse($this->alert('r2', 'R87.13 paid to Premier a/c..379711 @ Eft. Ref.Refund. 2Jun 00:00', '2026-06-02 08:00'));
        self::assertSame('paid_to', $other->transactions[0]->rule);
    }

    public function testReceivingSideFirstWaitsThenPairsWhenThePayingSideArrives(): void
    {
        $mailbox = $this->mailbox(['r1' => $this->alert('r1', self::RECEIVED, '2026-06-09 23:57')]);
        $summary = $this->importer()->run($mailbox);
        self::assertSame(1, $summary->transactions['waiting']);
        self::assertSame(0, TransactionJournal::count());
        $received = MailTransaction::query()->firstOrFail();
        self::assertSame(MailTransaction::PENDING, $received->status);
        self::assertStringContainsString('Waiting for the matching "paid from" email until 2026-06-12 23:56', (string) $received->error);
        self::assertSame('pending', MailMessageModel::query()->where('provider_message_id', 'r1')->value('status'));
        self::assertSame([], $this->provider->labelsOf('r1'));

        // FNB's "Payments for <day>" email with the paying side comes in later
        $this->provider->messages['p1'] = $this->alert('p1', self::PAYING, '2026-06-10 01:58');
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame(1, $summary->transactions['created']);
        self::assertSame(1, $summary->transactions['paired']);

        self::assertSame(1, TransactionJournal::count());
        $journal = $this->journal();
        self::assertSame('Withdrawal', $journal->transactionType->type);
        self::assertSame($this->cheque->id, $this->accountOf($journal, false));
        self::assertSame($this->card->id, $this->accountOf($journal, true));
        self::assertSame('Fnbcc Card 100200', $journal->description);
        self::assertStringContainsString("\nPaid into Credit card: FNB :-) ".self::RECEIVED.' | Received: 2026-06-09T23:57:00+02:00 | Message: main/r1', (string) $journal->notes()->first()->text);

        self::assertSame(2, MailTransaction::query()->count());
        self::assertSame([MailTransaction::IMPORTED], MailTransaction::query()->distinct()->pluck('status')->all());
        self::assertSame([$journal->transaction_group_id], MailTransaction::query()->distinct()->pluck('transaction_group_id')->all());
        self::assertSame(['p1' => 'imported', 'r1' => 'imported'], MailMessageModel::query()->orderBy('provider_message_id')->pluck('status', 'provider_message_id')->all());
        self::assertSame(['Firefly/Imported'], $this->provider->labelsOf('r1'));
        self::assertSame(['Firefly/Imported'], $this->provider->labelsOf('p1'));
    }

    public function testPayingSideFirstIsMovedToTheReceivingAccount(): void
    {
        $mailbox = $this->mailbox(['p1' => $this->alert('p1', self::PAYING, '2026-06-09 22:14')]);
        $this->importer()->run($mailbox);
        $journal = $this->journal();
        self::assertSame('Expense account', Account::query()->with('accountType')->findOrFail($this->accountOf($journal, true))->accountType->type);

        $this->provider->messages['r1'] = $this->alert('r1', self::RECEIVED, '2026-06-09 23:57');
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame(1, $summary->transactions['paired']);
        self::assertSame(0, $summary->transactions['created']);
        self::assertSame(1, TransactionJournal::count());
        $journal = $this->journal();
        self::assertSame('Withdrawal', $journal->transactionType->type);
        self::assertSame($this->card->id, $this->accountOf($journal, true));

        // a paired withdrawal is never used twice: another payment of the same amount waits for its own paying side
        $this->provider->messages['r2'] = $this->alert('r2', 'R412.50 paid to FNB card a/c..110149. 10Jun 07:30', '2026-06-10 07:31');
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame(1, $summary->transactions['waiting']);
        self::assertSame(1, TransactionJournal::count());
    }

    public function testBothSidesInOneRunAreOneTransaction(): void
    {
        // the receiving side is fetched first, but paying sides are written first
        $mailbox = $this->mailbox(['r1' => $this->alert('r1', self::RECEIVED, '2026-06-09 23:57'), 'p1' => $this->alert('p1', self::PAYING, '2026-06-10 01:58')]);
        $summary = $this->importer()->run($mailbox);
        self::assertSame(1, $summary->transactions['created']);
        self::assertSame(1, $summary->transactions['paired']);
        self::assertSame(1, TransactionJournal::count());
        self::assertSame($this->card->id, $this->accountOf($this->journal(), true));
    }

    public function testPaymentIntoAnAssetAccountBecomesATransfer(): void
    {
        $savings = $this->createAccount($this->user, 'Savings', 'asset', '432588', 'ZAR');
        $mailbox = $this->mailbox([
            'p1' => $this->alert('p1', self::PAYING, '2026-06-09 22:14'),
            'r1' => $this->alert('r1', 'R412.50 paid to Savings Account a/c..432588. 9Jun 22:15', '2026-06-09 22:16'),
        ]);
        $this->importer()->run($mailbox);
        $journal = $this->journal();
        self::assertSame('Transfer', $journal->transactionType->type);
        self::assertSame($this->cheque->id, $this->accountOf($journal, false));
        self::assertSame($savings->id, $this->accountOf($journal, true));
    }

    public function testWithoutAPayingSideItIsBookedAsADepositAfterTheWait(): void
    {
        $mailbox = $this->mailbox(['r1' => $this->alert('r1', self::RECEIVED, '2026-06-09 23:57')]);
        $this->importer()->run($mailbox);
        self::assertSame(0, TransactionJournal::count());

        Carbon::setTestNow(Carbon::parse('2026-06-12 23:55', 'Africa/Johannesburg'));
        self::assertSame(1, $this->importer()->run($mailbox->refresh())->transactions['waiting']);

        Carbon::setTestNow(Carbon::parse('2026-06-12 23:57', 'Africa/Johannesburg'));
        $summary = $this->importer()->run($mailbox->refresh());
        self::assertSame(1, $summary->transactions['created']);
        $journal = $this->journal();
        self::assertSame('Deposit', $journal->transactionType->type);
        self::assertSame('Payment into FNB card ..110149', $journal->description);
        self::assertSame($this->card->id, $this->accountOf($journal, true));
        self::assertSame('Payment received', Account::query()->findOrFail($this->accountOf($journal, false))->name);
        self::assertSame(['Firefly/Imported'], $this->provider->labelsOf('r1'));

        // a paying side that turns up later is a separate withdrawal again (the receiving side was already booked)
        $this->provider->messages['p1'] = $this->alert('p1', self::PAYING, '2026-06-10 01:58');
        $this->importer()->run($mailbox->refresh());
        self::assertSame(2, TransactionJournal::count());
    }

    public function testTwoPossiblePayingSidesAreNotGuessed(): void
    {
        $mailbox = $this->mailbox([
            'p1' => $this->alert('p1', self::PAYING, '2026-06-10 01:58'),
            'p2' => $this->alert('p2', 'R412.50 paid from Fusion Private a/c..379711 @ Eft. Avail R9000. Ref.Gym. 9Jun 20:00', '2026-06-09 20:01'),
            'r1' => $this->alert('r1', self::RECEIVED, '2026-06-09 23:57'),
        ]);
        $summary = $this->importer()->run($mailbox);
        self::assertSame(2, $summary->transactions['created']);
        self::assertSame(1, $summary->transactions['waiting']);
        $received = MailTransaction::query()->where('payload->rule', PaymentPairing::RULE)->firstOrFail();
        self::assertStringContainsString('2 "paid from" payments match, so none is picked', (string) $received->error);

        Carbon::setTestNow(Carbon::parse('2026-06-13 08:00', 'Africa/Johannesburg'));
        $this->importer()->run($mailbox->refresh());
        self::assertSame(3, TransactionJournal::count());
        // both withdrawals still go to their expense accounts; only the deposit touches the card
        self::assertSame(1, TransactionJournal::query()->whereHas('transactions', fn ($q) => $q->where('account_id', $this->card->id))->count());
    }

    public function testShadowModeOnlyReportsTheReceivingSide(): void
    {
        $mailbox = $this->mailbox(['r1' => $this->alert('r1', self::RECEIVED, '2026-06-09 23:57')], Mailbox::SHADOW);
        $summary = $this->importer()->run($mailbox);
        self::assertSame(1, $summary->transactions['shadow_would_create']);
        self::assertSame(0, TransactionJournal::count());
    }

    private function importer(): MailImporter
    {
        return new MailImporter(app(BankRegistry::class), fn (Mailbox $m): MailProvider => $this->provider);
    }

    /**
     * @param array<string, MailMessage> $messages
     */
    private function mailbox(array $messages, string $mode = Mailbox::LIVE): Mailbox
    {
        $this->provider = new FakeMailProvider($messages);

        return Mailbox::create([
            'user_group_id' => $this->user->user_group_id, 'user_id' => $this->user->id, 'name' => 'main', 'provider' => 'gmail', 'enabled' => true, 'mode' => $mode,
            'query'         => '', 'lookback_days' => 30, 'banks' => ['fnb'], 'oauth' => null,
        ]);
    }

    private function alert(string $id, string $line, string $receivedAt): MailMessage
    {
        return new MailMessage('main', $id, Carbon::parse($receivedAt, 'Africa/Johannesburg'), 'incontact@fnb.co.za', 'FNB :-) '.$line, [self::AUTH], [
            "Dear valued customer\nFNB :-) {$line}\nPlease do NOT reply to this message as it is sent from an unattended mailbox.",
        ]);
    }

    private function journal(): TransactionJournal
    {
        return TransactionJournal::with('transactionType', 'transactions')->firstOrFail();
    }

    private function accountOf(TransactionJournal $journal, bool $destination): int
    {
        return (int) $journal->transactions()->where('amount', $destination ? '>' : '<', 0)->value('account_id');
    }
}
