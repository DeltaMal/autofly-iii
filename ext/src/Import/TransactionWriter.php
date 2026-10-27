<?php

/*
 * TransactionWriter.php
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

namespace FireflyIII\Ext\Import;

use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;

/**
 * The only code path in ext that creates transactions (ext/docs/00 "Shared building blocks").
 *
 * Stores through upstream's TransactionGroupRepository (rules, webhooks and running balances work as they do
 * for API imports), refuses an external_id that already exists in the group (soft-deleted included), uses
 * the account's currency, and adds an import tag ("Email import Y-m-d" / "Statement import Y-m-d").
 *
 * Booking (the Python importer's transfer matrix, ext/docs/06 section 7):
 *   asset -> asset            transfer
 *   asset -> liability        withdrawal, liability as destination
 *   liability -> asset        deposit, liability as source
 *   not tracked -> tracked    deposit on the tracked account (opposing name)
 *   tracked -> not tracked    withdrawal from the tracked account (opposing name)
 */
final class TransactionWriter
{
    public const string SOURCE_EMAIL     = 'Email import';
    public const string SOURCE_STATEMENT = 'Statement import';

    private readonly ExternalIdLookup $lookup;

    public function __construct(private readonly User $user, private readonly UserGroup $userGroup, private readonly string $source)
    {
        $this->lookup = new ExternalIdLookup($userGroup);
    }

    /**
     * @throws DuplicateExternalIdException when the external id is already in Firefly
     * @throws DuplicateTransactionException when upstream's duplicate-hash check fires
     */
    public function store(TransactionRow $row): TransactionGroup
    {
        if (null !== $row->externalId && '' !== $row->externalId && null !== ($existing = $this->lookup->find($row->externalId))) {
            throw new DuplicateExternalIdException($row->externalId, $existing['group_id'], $existing['deleted']);
        }

        /** @var TransactionGroupRepositoryInterface $repository */
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($this->user);
        $repository->setUserGroup($this->userGroup);

        return $repository->store([
            'user'                    => $this->user,
            'user_group'              => $this->userGroup,
            'group_title'             => null,
            'error_if_duplicate_hash' => true,
            'batch_submission'        => false,
            'apply_rules'             => $row->applyRules,
            'fire_webhooks'           => true,
            'transactions'            => [$this->transaction($row)],
        ]);
    }

    public function importTag(): string
    {
        return sprintf('%s %s', $this->source, today(config('app.timezone'))->format('Y-m-d'));
    }

    /**
     * @return array<string, mixed> one entry of 'transactions', shaped like the API StoreRequest builds it
     */
    public function transaction(TransactionRow $row): array
    {
        [$type, $source, $destination] = $this->booking($row);
        $currency                      = $this->currency($row->account);
        $tags                          = array_values(array_unique([...$row->tags, $this->importTag()]));

        return [
            'type'                  => $type,
            'date'                  => $row->date->copy(),
            'order'                 => 0,
            'currency_id'           => $currency?->id,
            'currency_code'         => $currency?->code,
            'foreign_currency_id'   => null,
            'foreign_currency_code' => null,
            'amount'                => $row->amount,
            'foreign_amount'        => null,
            'description'           => '' === trim($row->description) ? '(no description)' : $row->description,
            'source_id'             => $source['id'] ?? null,
            'source_name'           => $source['name'] ?? null,
            'source_iban'           => null,
            'source_number'         => null,
            'source_bic'            => null,
            'destination_id'        => $destination['id'] ?? null,
            'destination_name'      => $destination['name'] ?? null,
            'destination_iban'      => null,
            'destination_number'    => null,
            'destination_bic'       => null,
            'budget_id'             => null,
            'budget_name'           => null,
            'category_id'           => null,
            'category_name'         => null,
            'bill_id'               => null,
            'bill_name'             => null,
            'piggy_bank_id'         => null,
            'piggy_bank_name'       => null,
            'reconciled'            => $row->reconciled,
            'notes'                 => $row->notes,
            'tags'                  => $tags,
            'internal_reference'    => $row->internalReference,
            'external_id'           => $row->externalId,
            'original_source'       => sprintf('ff3-v%s-ext', config('firefly.version')),
            'recurrence_id'         => null,
            'bunq_payment_id'       => null,
            'external_url'          => null,
            'sepa_cc'               => null,
            'sepa_ct_op'            => null,
            'sepa_ct_id'            => null,
            'sepa_db'               => null,
            'sepa_country'          => null,
            'sepa_ep'               => null,
            'sepa_ci'               => null,
            'sepa_batch_id'         => null,
            'interest_date'         => null,
            'book_date'             => null,
            'process_date'          => null,
            'due_date'              => null,
            'payment_date'          => null,
            'invoice_date'          => null,
            'latitude'              => null,
            'longitude'             => null,
            'zoom_level'            => null,
        ];
    }

    /**
     * @return array{0: string, 1: array{id?: int, name?: string}, 2: array{id?: int, name?: string}}
     */
    private function booking(TransactionRow $row): array
    {
        $out = 'out' === $row->direction;
        if (null === $row->counterAccount) {
            return $out
                ? ['withdrawal', ['id' => $row->account->id], ['name' => $row->counterpartyName]]
                : ['deposit', ['name' => $row->counterpartyName], ['id' => $row->account->id]];
        }
        [$from, $to] = $out ? [$row->account, $row->counterAccount] : [$row->counterAccount, $row->account];
        $fromIsLiability = self::isLiability($from);
        $toIsLiability   = self::isLiability($to);
        $type            = match (true) {
            !$fromIsLiability && $toIsLiability => 'withdrawal',
            $fromIsLiability && !$toIsLiability => 'deposit',
            default                             => 'transfer',
        };

        return [$type, ['id' => $from->id], ['id' => $to->id]];
    }

    private static function isLiability(Account $account): bool
    {
        return in_array($account->accountType->type, config('firefly.valid_liabilities'), true);
    }

    private function currency(Account $account): ?TransactionCurrency
    {
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($this->user);

        return $repository->getAccountCurrency($account);
    }
}
