<?php

/*
 * AutoBudgetTestHelpers.php
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

namespace FireflyIII\Ext\Tests\Feature\Budgets;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Jobs\CreateAutoBudgetLimits;
use FireflyIII\Models\Account;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Laravel\Passport\Passport;

/**
 * Shared by the auto-budget feature tests: users on CP (anchor 25, rule "previous": 25 Sep - 22 Oct,
 * 23 Oct - 24 Nov, 25 Nov - 24 Dec 2026), auto-budgets, hand-set limits, the job and spending.
 */
trait AutoBudgetTestHelpers
{
    protected const array CP = ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => []];

    protected function cpUser(string $email): User
    {
        $user = $this->createUser($email);
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', 'CP');
        Preferences::setForUser($user, 'extPeriod', self::CP);

        return $user;
    }

    /**
     * The API guard is Passport's; CI has no OAuth keys, so this test makes a throwaway pair.
     */
    protected function actingAsApiUser(User $user): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        Passport::actingAs($user);
    }

    protected function autoBudget(User $user, string $name, AutoBudgetType $type, string $amount, string $period = 'monthly'): Budget
    {
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $budget     = $repository->store(['name' => $name, 'active' => true]);

        $autoBudget                          = new AutoBudget();
        $autoBudget->budget()->associate($budget);
        $autoBudget->transaction_currency_id = Amount::getPrimaryCurrencyByUserGroup($user->userGroup)->id;
        $autoBudget->auto_budget_type        = $type->value;
        $autoBudget->amount                  = $amount;
        $autoBudget->period                  = $period;
        $autoBudget->save();

        return $budget;
    }

    protected function limit(Budget $budget, string $start, string $end, string $amount): void
    {
        $limit                          = new BudgetLimit();
        $limit->budget_id               = $budget->id;
        $limit->transaction_currency_id = Amount::getPrimaryCurrencyByUserGroup($budget->user->userGroup)->id;
        $limit->start_date              = Carbon::parse($start, config('app.timezone'))->startOfDay();
        $limit->end_date                = Carbon::parse($end, config('app.timezone'))->endOfDay();
        $limit->amount                  = $amount;
        $limit->save();
    }

    protected function runJob(User $user, string $date): void
    {
        $day = Carbon::parse($date.' 03:00:00', config('app.timezone'));
        $job = app(CreateAutoBudgetLimits::class, [$day]);
        $job->setDate($day);
        $job->setUser($user);
        $job->handle();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [start, end, amount] by start date
     */
    protected function limits(Budget $budget): array
    {
        return $budget->budgetlimits()->orderBy('start_date')->get()->map(static fn (BudgetLimit $limit): array => [
            $limit->start_date->format('Y-m-d'),
            $limit->end_date->format('Y-m-d'),
            rtrim(rtrim(bcadd((string) $limit->amount, '0', 2), '0'), '.'),
        ])->all();
    }

    protected function assertNoOverlaps(Budget $budget): void
    {
        $limits = $this->limits($budget);
        for ($i = 1; $i < count($limits); ++$i) {
            self::assertLessThan($limits[$i][0], $limits[$i - 1][1], sprintf('%s overlaps %s', implode('/', $limits[$i - 1]), implode('/', $limits[$i])));
        }
    }

    protected function spend(User $user, Account $account, Budget $budget, string $date, string $amount): void
    {
        $currency   = Amount::getPrimaryCurrencyByUserGroup($user->userGroup);
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($user);
        $repository->setUserGroup($user->userGroup);
        $transaction = [
            'type'             => 'withdrawal',
            'date'             => Carbon::parse($date.' 12:00:00', config('app.timezone')),
            'order'            => 0,
            'currency_id'      => $currency->id,
            'currency_code'    => $currency->code,
            'amount'           => $amount,
            'description'      => 'Spent on '.$budget->name,
            'source_id'        => $account->id,
            'destination_name' => 'Synthetic Shop',
            'budget_id'        => $budget->id,
            'reconciled'       => false,
            'tags'             => [],
        ];
        foreach (['foreign_currency_id', 'foreign_currency_code', 'foreign_amount', 'source_name', 'source_iban', 'source_number', 'source_bic', 'destination_id',
            'destination_iban', 'destination_number', 'destination_bic', 'budget_name', 'category_id', 'category_name', 'bill_id', 'bill_name',
            'piggy_bank_id', 'piggy_bank_name', 'notes', 'internal_reference', 'external_id', 'original_source', 'recurrence_id', 'bunq_payment_id',
            'external_url', 'sepa_cc', 'sepa_ct_op', 'sepa_ct_id', 'sepa_db', 'sepa_country', 'sepa_ep', 'sepa_ci', 'sepa_batch_id', 'interest_date',
            'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date', 'latitude', 'longitude', 'zoom_level'] as $key) {
            $transaction[$key] = null;
        }
        $repository->store([
            'user' => $user, 'user_group' => $user->userGroup, 'group_title' => null, 'error_if_duplicate_hash' => false, 'batch_submission' => false,
            'apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [$transaction],
        ]);
    }
}
