<?php

/*
 * MailImporter.php
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
use Closure;
use FireflyIII\Ext\Banks\AccountResolution;
use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Import\DuplicateExternalIdException;
use FireflyIII\Ext\Import\ExternalIdLookup;
use FireflyIII\Ext\Import\TransactionRow;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Mail\Gmail\GmailProvider;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Models\MailMessage as MailMessageModel;
use FireflyIII\Ext\Models\MailTransaction;
use FireflyIII\Models\Account;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The email import pipeline (ext/docs/03 "Pipeline"), a port of the Python importer's pipeline.py with
 * synchronous writes inside Firefly:
 *
 * 1. lock per mailbox; 2. retry failed (optional); 3. search, fetch and parse new or unmatched emails, saving
 * each message and its transactions (first sighting wins); 4. resolve and store pending/unmapped transactions
 * of the user group (shadow: never write); 5. refresh message statuses; 6. sync labels (live only);
 * 7. record the poll on the mailbox. A dry run is shadow mode inside a transaction that is rolled back.
 */
final class MailImporter
{
    public const string NO_TEMPLATE    = 'no bank template';
    public const string NOT_AUTHENTIC  = 'failed sender authentication';

    /** @var Closure(Mailbox): MailProvider */
    private readonly Closure $providers;

    /**
     * @param null|Closure(Mailbox): MailProvider $providers
     */
    public function __construct(private readonly BankRegistry $banks, ?Closure $providers = null)
    {
        $this->providers = $providers ?? static fn (Mailbox $mailbox): MailProvider => app(GmailProvider::class, ['mailbox' => $mailbox, 'oauth' => app(Gmail\GmailOAuth::class)]);
    }

    public function run(Mailbox $mailbox, bool $dryRun = false, bool $retryFailed = false, ?Carbon $since = null): MailImportSummary
    {
        $summary = new MailImportSummary($mailbox->name, $dryRun, $dryRun || $mailbox->isShadow());
        if (!$mailbox->enabled) {
            $summary->skipped = 'disabled';

            return $summary;
        }
        $lock    = Cache::lock(sprintf('ext-mail-poll:%d', $mailbox->id), 7200);
        if (!$lock->get()) {
            $summary->skipped = 'locked (another poll is running)';

            return $summary;
        }

        try {
            if ($dryRun) {
                DB::beginTransaction();
            }

            try {
                $this->process($mailbox, $summary, $dryRun, $retryFailed, $since);
            } finally {
                if ($dryRun) {
                    DB::rollBack();
                }
            }
            if (!$dryRun) {
                $mailbox->last_polled_at = Carbon::now();
                $mailbox->last_error     = $summary->labelError;
                $mailbox->save();
            }
        } catch (Throwable $e) {
            Log::error(sprintf('ext mail poll of mailbox #%d failed: %s', $mailbox->id, $e->getMessage()));
            $summary->error = $e->getMessage();
            if (!$dryRun) {
                $mailbox->refresh();
                $mailbox->last_polled_at = Carbon::now();
                $mailbox->last_error     = $e instanceof MailProviderException && $e->reconnectRequired ? Mailbox::RECONNECT_REQUIRED.': '.$e->getMessage() : $e->getMessage();
                $mailbox->save();
            }
        } finally {
            $lock->release();
        }

        return $summary;
    }

    private function process(Mailbox $mailbox, MailImportSummary $summary, bool $dryRun, bool $retryFailed, ?Carbon $since): void
    {
        $group   = UserGroup::findOrFail($mailbox->user_group_id);
        $user    = User::findOrFail($mailbox->user_id);

        // 2. retry failed
        if ($retryFailed) {
            MailTransaction::query()->forGroup($group)->where('status', MailTransaction::FAILED)->update(['status' => MailTransaction::PENDING, 'attempts' => 0]);
            MailMessageModel::query()->forGroup($group)->where('mailbox_id', $mailbox->id)->where('status', MailMessageModel::FAILED)->update(['status' => MailMessageModel::PENDING]);
        }

        // 3. search and parse
        $banks    = $this->mailboxBanks($mailbox);
        $provider = ($this->providers)($mailbox);
        $senders  = [];
        foreach ($banks as $bank) {
            array_push($senders, ...$bank->emailSenders());
        }
        $since  ??= Carbon::now()->subDays($mailbox->lookback_days);
        $query    = GmailProvider::buildQuery($senders, (string) $mailbox->query, $since, $mailbox->label_imported);
        $summary->query = $query;
        foreach ($provider->search($query) as $id) {
            $existing = MailMessageModel::query()->where('mailbox_id', $mailbox->id)->where('provider_message_id', $id)->first();
            if (null !== $existing && MailMessageModel::UNMATCHED !== $existing->status) {
                ++$summary->skippedMessages;

                continue;
            }
            $this->processMessage($mailbox, $group, $provider, $banks, $id, $existing, $summary);
        }

        // 4. resolve and store
        $this->load($group, $user, $dryRun, $summary);

        // 5. message statuses
        $this->refreshStatuses($group);

        // 6. labels
        if (!$dryRun && !$mailbox->isShadow()) {
            $this->syncLabels($mailbox, $provider, $summary);
        }
    }

    /**
     * @param list<\FireflyIII\Ext\Banks\BankDefinition> $banks
     */
    private function processMessage(Mailbox $mailbox, UserGroup $group, MailProvider $provider, array $banks, string $id, ?MailMessageModel $existing, MailImportSummary $summary): void
    {
        $message  = $provider->fetch($id);
        ++$summary->fetched;
        $result   = null;
        $reason   = null;
        $template = null;
        $profile  = null;
        foreach ($banks as $bank) {
            foreach ($bank->emailTemplates() as $candidate) {
                if ($candidate instanceof EmailTemplate && $candidate->matches($message)) {
                    $template = $candidate;
                    $profile  = BankProfile::forBank($group, $bank->id());

                    break 2;
                }
            }
        }
        if (null === $template) {
            $reason = self::NO_TEMPLATE;
        } elseif (!$template->isAuthentic($message, $profile->require_authenticated)) {
            $reason = self::NOT_AUTHENTIC;
        } else {
            try {
                $result = $template->parse($message, ['tags' => array_values((array) $profile->tags)]);
            } catch (Throwable $e) {
                $reason = $e->getMessage();
            }
        }
        $outcome  = null === $result ? ParseResult::UNMATCHED : $result->outcome();
        $raw      = null;
        if (ParseResult::UNMATCHED === $outcome) {
            try {
                $raw = $provider->fetchRaw($id);
            } catch (Throwable $e) {
                Log::warning(sprintf('Could not fetch the raw email %s: %s', $id, $e->getMessage()));
            }
        }

        DB::transaction(static function () use ($mailbox, $group, $message, $id, $existing, $result, $reason, $outcome, $raw, $summary): void {
            $row = $existing ?? new MailMessageModel(['user_group_id' => $group->id, 'mailbox_id' => $mailbox->id, 'provider_message_id' => $id]);
            $row->fill([
                'received_at'    => $message->receivedAt,
                'sender'         => mb_substr($message->sender, 0, 255),
                'subject'        => mb_substr($message->subject, 0, 1024),
                'outcome'        => $outcome,
                'status'         => ParseResult::UNMATCHED === $outcome ? MailMessageModel::UNMATCHED : MailMessageModel::PENDING,
                'label_synced'   => false,
                'unmatched_text' => ParseResult::UNMATCHED === $outcome ? implode("\n", null === $result ? [(string) $reason] : $result->unmatched) : null,
                'bodies'         => $message->bodies,
                'raw'            => $raw,
                'error'          => $reason,
            ]);
            $row->save();
            ++$summary->messages[$outcome];
            foreach (null === $result ? [] : $result->transactions as $parsed) {
                // first sighting wins
                $transaction = MailTransaction::query()->forGroup($group)->where('external_id', $parsed->externalId)->first()
                    ?? MailTransaction::create(['user_group_id' => $group->id, 'external_id' => $parsed->externalId, 'status' => MailTransaction::PENDING, 'attempts' => 0, 'payload' => $parsed->toArray()]);
                DB::table('ext_mail_message_transactions')->insertOrIgnore(['mail_message_id' => $row->id, 'mail_transaction_id' => $transaction->id]);
                ++$summary->parsedTransactions;
            }
        });
    }

    private function load(UserGroup $group, User $user, bool $dryRun, MailImportSummary $summary): void
    {
        $transactions = MailTransaction::query()->forGroup($group)
            ->whereIn('status', [MailTransaction::PENDING, MailTransaction::UNMAPPED, MailTransaction::SHADOW_WOULD_CREATE])
            ->with('messages.mailbox')->orderBy('id')->get();
        if (0 === $transactions->count()) {
            return;
        }
        $resolver     = new AccountResolver($group);
        $lookup       = new ExternalIdLookup($group);
        $existing     = $lookup->findMany($transactions->pluck('external_id')->all());
        $writer       = new TransactionWriter($user, $group, TransactionWriter::SOURCE_EMAIL);
        foreach ($transactions as $transaction) {
            $live   = !$dryRun && $transaction->messages->contains(static fn (MailMessageModel $m): bool => null !== $m->mailbox && Mailbox::LIVE === $m->mailbox->mode);

            try {
                $plan = $this->plan($transaction->parsed(), $resolver, $group);
            } catch (Throwable $e) {
                // a damaged row never stops the run
                $transaction->fill(['status' => MailTransaction::FAILED, 'error' => mb_substr('Cannot read the stored transaction: '.$e->getMessage(), 0, 2000)])->save();
                ++$summary->transactions['failed'];

                continue;
            }
            if (null === $plan['row']) {
                $transaction->fill(['status' => MailTransaction::UNMAPPED, 'error' => $plan['problem'], 'account_id' => null])->save();
                ++$summary->transactions['unmapped'];

                continue;
            }
            $transaction->account_id = $plan['row']->account->id;
            if (array_key_exists($transaction->external_id, $existing)) {
                $transaction->fill(['status' => $live ? MailTransaction::IMPORTED : MailTransaction::SHADOW_EXISTS, 'transaction_group_id' => $existing[$transaction->external_id]['group_id'], 'error' => null])->save();
                ++$summary->transactions[$live ? 'already_in_firefly' : 'shadow_exists'];

                continue;
            }
            if (!$live) {
                $transaction->fill(['status' => MailTransaction::SHADOW_WOULD_CREATE, 'error' => null])->save();
                ++$summary->transactions['shadow_would_create'];

                continue;
            }

            try {
                $stored = $writer->store($plan['row']);
                $transaction->fill(['status' => MailTransaction::IMPORTED, 'transaction_group_id' => $stored->id, 'error' => null])->save();
                ++$summary->transactions['created'];
            } catch (DuplicateExternalIdException $e) {
                $transaction->fill(['status' => MailTransaction::IMPORTED, 'transaction_group_id' => $e->groupId, 'error' => null])->save();
                ++$summary->transactions['already_in_firefly'];
            } catch (Throwable $e) {
                $attempts = $transaction->attempts + 1;
                $transaction->fill(['attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 2000), 'status' => $attempts >= MailTransaction::MAX_ATTEMPTS ? MailTransaction::FAILED : MailTransaction::PENDING])->save();
                ++$summary->transactions['failed'];
                Log::warning(sprintf('ext mail import of %s failed: %s', $transaction->external_id, $e->getMessage()));
            }
        }
    }

    /**
     * Account resolution plus the transfer matrix (ext/docs/06 section 7).
     *
     * @return array{row: null|TransactionRow, problem: null|string}
     */
    public function plan(ParsedTransaction $parsed, AccountResolver $resolver, UserGroup $group): array
    {
        $source = $this->resolveSource($parsed, $resolver);
        if ('transfer' !== $parsed->kind) {
            return $source->resolved()
                ? ['row' => $this->row($parsed, $this->account($group, $source->accountId), 'deposit' === $parsed->kind ? 'in' : 'out', $parsed->counterparty), 'problem' => null]
                : ['row' => null, 'problem' => $source->problem];
        }
        $target = $resolver->resolve($parsed->bankId, (string) $parsed->counterAccountRef, false);
        if ($source->resolved() && $target->resolved()) {
            return ['row' => $this->row($parsed, $this->account($group, $source->accountId), 'out', $parsed->counterparty, $this->account($group, $target->accountId)), 'problem' => null];
        }
        if ($source->resolved() && $target->notFound()) {
            return ['row' => $this->row($parsed, $this->account($group, $source->accountId), 'out', $parsed->counterparty), 'problem' => null];
        }
        if ($source->notFound() && $target->resolved()) {
            return ['row' => $this->row($parsed, $this->account($group, $target->accountId), 'in', $parsed->accountLabel), 'problem' => null];
        }

        return ['row' => null, 'problem' => implode('; ', array_values(array_filter([$source->problem, $target->problem])))];
    }

    private function resolveSource(ParsedTransaction $parsed, AccountResolver $resolver): AccountResolution
    {
        // an explicit card mapping (ext_account_identifiers kind "card") comes first; otherwise accounts.py
        if (null !== $parsed->cardRef) {
            $card = $resolver->resolve($parsed->bankId, $parsed->cardRef, false, AccountIdentifier::KIND_CARD);
            if ($card->resolved() || AccountResolution::MISSING_MAPPING === $card->kind) {
                return $card;
            }
        }

        return $resolver->resolve($parsed->bankId, $parsed->accountRef, true);
    }

    private function row(ParsedTransaction $parsed, Account $account, string $direction, string $counterparty, ?Account $counterAccount = null): TransactionRow
    {
        $received = $parsed->receivedAt->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP');

        return new TransactionRow(
            account: $account,
            direction: $direction,
            amount: $parsed->amount,
            date: $parsed->occurredAt->copy()->setTimezone(config('app.timezone')),
            description: $parsed->description,
            counterpartyName: $counterparty,
            counterAccount: $counterAccount,
            tags: $parsed->tags,
            notes: sprintf('%s | Email: %s | Received: %s | Message: %s/%s', $parsed->rawText, $parsed->subject, $received, $parsed->mailboxId, $parsed->messageId),
            externalId: $parsed->externalId,
            internalReference: null === $parsed->cardRef ? null : 'card..'.$parsed->cardRef,
            applyRules: true,
        );
    }

    private function account(UserGroup $group, int $id): Account
    {
        return Account::query()->where('user_group_id', $group->id)->with('accountType')->findOrFail($id);
    }

    /**
     * Python's message status derivation, plus the shadow statuses. A status change resets label_synced.
     */
    public function refreshStatuses(UserGroup $group): void
    {
        $messages = MailMessageModel::query()->forGroup($group)
            ->whereNotIn('status', [MailMessageModel::IMPORTED, MailMessageModel::IGNORED, MailMessageModel::UNMATCHED])
            ->with(['transactions', 'mailbox'])->get();
        foreach ($messages as $message) {
            $status = self::deriveStatus($message);
            if ($status !== $message->status) {
                $message->status       = $status;
                $message->label_synced = false;
                $message->save();
            }
        }
    }

    public static function deriveStatus(MailMessageModel $message): string
    {
        if (ParseResult::UNMATCHED === $message->outcome) {
            return MailMessageModel::UNMATCHED;
        }
        $statuses = $message->transactions->pluck('status')->all();
        if ([] === $statuses) {
            return MailMessageModel::IGNORED;
        }
        if ([] === array_diff($statuses, [MailTransaction::IMPORTED])) {
            return MailMessageModel::IMPORTED;
        }
        if (in_array(MailTransaction::FAILED, $statuses, true)) {
            return MailMessageModel::FAILED;
        }
        if (null !== $message->mailbox && $message->mailbox->isShadow()) {
            if ([] === array_diff($statuses, [MailTransaction::IMPORTED, MailTransaction::SHADOW_EXISTS])) {
                return MailMessageModel::SHADOW_EXISTS;
            }
            if (in_array(MailTransaction::SHADOW_WOULD_CREATE, $statuses, true) && [] === array_diff($statuses, [MailTransaction::IMPORTED, MailTransaction::SHADOW_EXISTS, MailTransaction::SHADOW_WOULD_CREATE])) {
                return MailMessageModel::SHADOW_WOULD_CREATE;
            }
        }

        return MailMessageModel::PENDING;
    }

    private function syncLabels(Mailbox $mailbox, MailProvider $provider, MailImportSummary $summary): void
    {
        $messages = MailMessageModel::query()->where('mailbox_id', $mailbox->id)->where('label_synced', false)->get();
        $imported = $messages->where('status', MailMessageModel::IMPORTED);
        $unmatched = $messages->where('status', MailMessageModel::UNMATCHED);

        try {
            if ($imported->count() > 0) {
                $provider->modifyLabels($imported->pluck('provider_message_id')->all(), [$mailbox->label_imported], [$mailbox->label_unmatched]);
            }
            if ($unmatched->count() > 0) {
                $provider->modifyLabels($unmatched->pluck('provider_message_id')->all(), [$mailbox->label_unmatched], []);
            }
        } catch (Throwable $e) {
            // leave label_synced = 0: retried on the next run
            $summary->labelError = 'Labels: '.$e->getMessage();

            return;
        }
        MailMessageModel::query()->whereIn('id', $messages->pluck('id')->all())->update(['label_synced' => true]);
        $summary->labelled = $imported->count() + $unmatched->count();
    }

    /**
     * Switching a mailbox from shadow to live deletes its shadow rows, so live mode looks at everything again.
     * Transactions also seen by another mailbox, or already imported, are kept.
     */
    public static function leaveShadowMode(Mailbox $mailbox): void
    {
        DB::transaction(static function () use ($mailbox): void {
            $messageIds = MailMessageModel::query()->where('mailbox_id', $mailbox->id)->pluck('id')->all();
            $txIds      = DB::table('ext_mail_message_transactions')->whereIn('mail_message_id', $messageIds)->pluck('mail_transaction_id')->unique()->all();
            $shared     = DB::table('ext_mail_message_transactions')->whereIn('mail_transaction_id', $txIds)->whereNotIn('mail_message_id', $messageIds)->pluck('mail_transaction_id')->unique()->all();
            MailMessageModel::query()->whereIn('id', $messageIds)->delete();
            MailTransaction::query()->whereIn('id', array_diff($txIds, $shared))->where('status', '!=', MailTransaction::IMPORTED)->delete();
        });
    }

    /** @return list<\FireflyIII\Ext\Banks\BankDefinition> */
    private function mailboxBanks(Mailbox $mailbox): array
    {
        $banks = [];
        foreach ((array) $mailbox->banks as $id) {
            if ($this->banks->has((string) $id) && BankProfile::forBank($mailbox->user_group_id, (string) $id)->enabled) {
                $banks[] = $this->banks->get((string) $id);
            }
        }

        return $banks;
    }
}
