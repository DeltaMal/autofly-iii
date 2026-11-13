<?php

/*
 * PaymentPairing.php
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

namespace FireflyIII\Ext\Mail;

use Carbon\Carbon;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Models\MailTransaction;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One payment between two of the user's own accounts, seen from both ends (ext/docs/03 "Payments seen from both
 * ends"): "R412.50 paid from Fusion a/c..111111 @ Eft. Ref.X" on the paying account and "R412.50 paid to FNB card
 * a/c..222222." on the receiving one. Booked separately they would be an expense plus an income; paired they are
 * one transaction from the paying account to the receiving account.
 *
 * The receiving side (rule payment_received) is the one that waits, because FNB sends it at once but batches EFT
 * "paid from" alerts into a later "Payments for <day>" email. It looks for exactly one paying side: an
 * email-imported "paid from" withdrawal in the same user group with the same bank and amount, dated from
 * WAIT_HOURS before to SKEW_MINUTES after it, from another account to an expense account (so not paired yet).
 * - One found: that withdrawal is moved to the receiving account through upstream's update service, with the type
 *   from the transfer matrix (asset -> card is a withdrawal to the liability, asset -> asset a transfer).
 * - None, or more than one (never guess): it waits until WAIT_HOURS after the payment, then is booked on its own
 *   as a deposit.
 */
final class PaymentPairing
{
    public const string RULE        = 'payment_received';
    public const string PAYING_RULE = 'paid_from';
    public const int WAIT_HOURS     = 72;
    public const int SKEW_MINUTES   = 60;

    public function __construct(private readonly User $user, private readonly UserGroup $group) {}

    public static function applies(ParsedTransaction $parsed): bool
    {
        return self::RULE === $parsed->rule;
    }

    /** Whether the paying side may still turn up. */
    public static function waiting(ParsedTransaction $received): bool
    {
        return Carbon::now()->lt($received->occurredAt->copy()->addHours(self::WAIT_HOURS));
    }

    /** The note on a receiving side that is still waiting. */
    public static function waitingNote(int $candidates, ParsedTransaction $received): string
    {
        $until = $received->occurredAt->copy()->addHours(self::WAIT_HOURS)->setTimezone(config('app.timezone'))->format('Y-m-d H:i');

        return 0 === $candidates
            ? sprintf('Waiting for the matching "paid from" email until %s; after that it is booked on its own.', $until)
            : sprintf('%d "paid from" payments match, so none is picked. Booked on its own after %s.', $candidates, $until);
    }

    /**
     * The paying-side journals this receiving side could belong to.
     *
     * @return list<TransactionJournal>
     */
    public function candidates(ParsedTransaction $received, Account $target): array
    {
        $from     = $received->occurredAt->copy()->subHours(self::WAIT_HOURS);
        $until    = $received->occurredAt->copy()->addMinutes(self::SKEW_MINUTES);
        $rows     = MailTransaction::query()->forGroup($this->group)
            ->where('status', MailTransaction::IMPORTED)
            ->whereNotNull('transaction_group_id')
            ->where('payload->rule', self::PAYING_RULE)
            ->where('created_at', '>=', $from->copy()->subDay()) // its email cannot have been parsed before the payment
            ->orderBy('id')
            ->get()
        ;
        $journals = [];
        foreach ($rows as $row) {
            $paying = $row->parsed();
            if ($paying->bankId !== $received->bankId || 0 !== bccomp($paying->amount, $received->amount, 2) || $paying->occurredAt->lt($from) || $paying->occurredAt->gt($until)) {
                continue;
            }
            $journal = $this->unpairedWithdrawal((int) $row->transaction_group_id, $received->amount, $target);
            if (null !== $journal) {
                $journals[$journal->id] = $journal;
            }
        }

        return array_values($journals);
    }

    /**
     * Moves the paying withdrawal to the receiving account and adds the receiving alert to its notes.
     */
    public function attach(TransactionJournal $journal, Account $target, ParsedTransaction $received): TransactionGroup
    {
        /** @var TransactionGroupRepositoryInterface $repository */
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($this->user);
        $repository->setUserGroup($this->group);
        $source     = self::side($journal, false)?->account;
        if (null === $source) {
            throw new RuntimeException(sprintf('Transaction journal #%d has no source account.', $journal->id));
        }
        $group      = $journal->transactionGroup;
        $oldHash    = $repository->getCompareHash($group);
        $objects    = TransactionGroupEventObjects::collectFromTransactionGroup($group);
        $notes      = trim((string) $journal->notes()->first()?->text);
        $line       = sprintf(
            'Paid into %s: %s | Received: %s | Message: %s/%s',
            $target->name,
            $received->rawText,
            $received->receivedAt->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP'),
            $received->mailboxId,
            $received->messageId
        );

        $group      = DB::transaction(static function () use ($repository, $group, $journal, $source, $target, $notes, $line): TransactionGroup {
            $updated = $repository->update($group, ['transactions' => [[
                'transaction_journal_id' => $journal->id,
                'type'                   => TransactionWriter::bookingType($source, $target),
                'source_id'              => $source->id,
                'destination_id'         => $target->id,
                'notes'                  => '' === $notes ? $line : $notes."\n".$line,
            ]]]);
            // upstream skips an account change it finds invalid without saying so: check, and undo the notes if so
            if (!$journal->transactions()->where('amount', '>', 0)->where('account_id', $target->id)->exists()) {
                throw new RuntimeException(sprintf('Firefly did not move transaction #%d to "%s".', $group->id, $target->name));
            }

            return $updated;
        });
        $objects->appendFromTransactionGroup($group);

        // as upstream's API update does: rules stay as they were, running balances and webhooks follow the change
        $flags                    = new TransactionGroupEventFlags();
        $flags->applyRules        = false;
        $flags->fireWebhooks      = true;
        $flags->recalculateCredit = $oldHash !== $repository->getCompareHash($group);
        event(new UpdatedSingleTransactionGroup($flags, $objects));
        event(new WebhookMessagesRequestSending());

        return $group;
    }

    /**
     * The only journal of the group when it is still an unpaired withdrawal of this amount: from another account to
     * an expense account.
     */
    private function unpairedWithdrawal(int $groupId, string $amount, Account $target): ?TransactionJournal
    {
        $journals    = TransactionJournal::query()
            ->where('user_group_id', $this->group->id)
            ->where('transaction_group_id', $groupId)
            ->with(['transactionType', 'transactions.account.accountType'])
            ->get()
        ;
        if (1 !== $journals->count()) {
            return null;
        }

        /** @var TransactionJournal $journal */
        $journal     = $journals->first();
        $source      = self::side($journal, false);
        $destination = self::side($journal, true);
        if (TransactionTypeEnum::WITHDRAWAL->value !== $journal->transactionType->type || null === $source || null === $destination) {
            return null;
        }
        if ($source->account_id === $target->id || AccountTypeEnum::EXPENSE->value !== $destination->account?->accountType?->type) {
            return null;
        }

        return 0 === bccomp(ltrim((string) $destination->amount, '-'), $amount, 2) ? $journal : null;
    }

    private static function side(TransactionJournal $journal, bool $destination): ?Transaction
    {
        return $journal->transactions->first(static fn (Transaction $t): bool => ($destination ? 1 : -1) === bccomp((string) $t->amount, '0', 12));
    }
}
