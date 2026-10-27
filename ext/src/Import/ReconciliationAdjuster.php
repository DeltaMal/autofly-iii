<?php

/*
 * ReconciliationAdjuster.php
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

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;

/**
 * Books a "reconciliation" transaction for an unexplained statement difference. Copies the logic of upstream's
 * private Account\ReconcileController::createReconciliation(): TransactionGroupFactory directly (so, like
 * upstream, no events and no rules), the account's reconciliation account, reconciled = true.
 */
final class ReconciliationAdjuster
{
    public function __construct(private readonly User $user) {}

    /**
     * @param string  $difference   bank minus Firefly: positive means money must come into the account
     * @param ?string $categoryName e.g. "Unexplained", so the difference stays visible in Firefly's reports
     */
    public function book(Account $account, Carbon $date, string $difference, string $description, ?string $categoryName = null, ?string $notes = null): ?TransactionGroup
    {
        if (0 === bccomp($difference, '0', 2)) {
            return null;
        }
        /** @var AccountRepositoryInterface $repository */
        $repository     = app(AccountRepositoryInterface::class);
        $repository->setUser($this->user);
        $reconciliation = $repository->getReconciliation($account);
        $currency       = $repository->getAccountCurrency($account) ?? Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);
        // upstream: a positive (Firefly minus bank) difference goes from the account to the reconciliation account
        [$source, $destination] = 1 === bccomp($difference, '0', 2) ? [$reconciliation, $account] : [$account, $reconciliation];

        /** @var TransactionGroupFactory $factory */
        $factory        = app(TransactionGroupFactory::class);
        $factory->setUser($this->user);

        return $factory->create([
            'user'         => $this->user,
            'user_group'   => $this->user->userGroup,
            'group_title'  => null,
            'transactions' => [[
                'user'                => $this->user,
                'user_group'          => $this->user->userGroup,
                'type'                => strtolower(TransactionTypeEnum::RECONCILIATION->value),
                'date'                => $date->copy(),
                'order'               => 0,
                'currency_id'         => $currency->id,
                'foreign_currency_id' => null,
                'amount'              => ltrim(bcadd($difference, '0', 2), '-'),
                'foreign_amount'      => null,
                'description'         => $description,
                'source_id'           => $source->id,
                'destination_id'      => $destination->id,
                'reconciled'          => true,
                'category_name'       => $categoryName,
                'notes'               => $notes,
            ]],
        ]);
    }
}
