<?php

/*
 * ParsedTransaction.php
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

/**
 * A transaction parsed from an alert email. Same fields as the Python importer's; money as strings.
 */
final readonly class ParsedTransaction
{
    /**
     * @param 'deposit'|'transfer'|'withdrawal' $kind
     * @param list<string>                      $tags
     */
    public function __construct(
        public string $externalId,
        public string $bankId,
        public string $rule,
        public string $kind,
        public string $amount,
        public string $currency,
        public Carbon $occurredAt,
        public string $description,
        public string $counterparty,
        public string $accountRef,
        public ?string $cardRef,
        public ?string $balanceAfter,
        public array $tags,
        public string $rawText,
        public string $mailboxId,
        public string $messageId,
        public string $subject,
        public Carbon $receivedAt,
        public ?string $counterAccountRef,
        public string $accountLabel,
    ) {}

    public function signedAmount(): string
    {
        return 'deposit' === $this->kind ? $this->amount : '-'.$this->amount;
    }

    /** The shape of the golden fixtures / the ext_mail_transactions.payload column. */
    public function toArray(): array
    {
        return [
            'external_id'         => $this->externalId,
            'bank_id'             => $this->bankId,
            'rule'                => $this->rule,
            'kind'                => $this->kind,
            'amount'              => $this->amount,
            'currency'            => $this->currency,
            'occurred_at'         => $this->occurredAt->format('Y-m-d\TH:i:sP'),
            'description'         => $this->description,
            'counterparty'        => $this->counterparty,
            'account_ref'         => $this->accountRef,
            'card_ref'            => $this->cardRef,
            'balance_after'       => $this->balanceAfter,
            'tags'                => $this->tags,
            'raw_text'            => $this->rawText,
            'mailbox_id'          => $this->mailboxId,
            'message_id'          => $this->messageId,
            'subject'             => $this->subject,
            'received_at'         => $this->receivedAt->copy()->utc()->format('Y-m-d\TH:i:sP'),
            'counter_account_ref' => $this->counterAccountRef,
            'account_label'       => $this->accountLabel,
            'signed_amount'       => $this->signedAmount(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['external_id'], (string) $data['bank_id'], (string) $data['rule'], (string) $data['kind'], (string) $data['amount'], (string) $data['currency'],
            Carbon::parse((string) $data['occurred_at'])->setTimezone(config('app.timezone')), (string) $data['description'], (string) $data['counterparty'], (string) $data['account_ref'],
            $data['card_ref'] ?? null, $data['balance_after'] ?? null, array_values((array) $data['tags']), (string) $data['raw_text'], (string) $data['mailbox_id'], (string) $data['message_id'],
            (string) $data['subject'], Carbon::parse((string) $data['received_at']), $data['counter_account_ref'] ?? null, (string) $data['account_label'],
        );
    }
}
