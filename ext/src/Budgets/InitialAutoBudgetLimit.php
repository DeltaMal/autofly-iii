<?php

/*
 * InitialAutoBudgetLimit.php
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

namespace FireflyIII\Ext\Budgets;

use FireflyIII\Events\Model\BudgetLimit\CreatedBudgetLimit;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\BudgetLimit;
use Illuminate\Support\Facades\Log;

/**
 * A budget created with a monthly auto-budget while on CP gets the current cycle's limit at once
 * (ext/docs/01-custom-periods.md, "Auto-budgets").
 *
 * Upstream's BudgetRepository::store() saves the auto-budget and right after it stores a first calendar-month
 * limit with the auto-budget amount (no event in between, and nothing marks that limit). ExtServiceProvider
 * wires two hooks: autoBudgetCreated() (Eloquent "created" on AutoBudget) remembers the budget, and
 * limitCreated() (the CreatedBudgetLimit event) recognises the very next limit of that budget as the one
 * auto-budget creation made and hands it to CustomPeriodAutoBudgets::replaceInitialLimit(). Any other limit,
 * and every limit of a non-CP user, is left alone. Bound as a singleton (one per request or job).
 */
final class InitialAutoBudgetLimit
{
    /** @var array<int, int> budget id => auto-budget id, waiting for the first limit */
    private array $pending = [];

    public function autoBudgetCreated(AutoBudget $autoBudget): void
    {
        $this->pending[(int) $autoBudget->budget_id] = (int) $autoBudget->id;
    }

    public function limitCreated(CreatedBudgetLimit $event): void
    {
        $limit = $event->budgetLimit;
        if (!array_key_exists((int) $limit->budget_id, $this->pending)) {
            return;
        }
        $autoBudgetId = $this->pending[(int) $limit->budget_id];
        unset($this->pending[(int) $limit->budget_id]);

        /** @var null|AutoBudget $autoBudget */
        $autoBudget   = AutoBudget::query()->find($autoBudgetId);
        if (!$autoBudget instanceof AutoBudget || !$this->isInitialLimit($autoBudget, $limit)) {
            return;
        }
        $user         = $autoBudget->budget->user;
        if (!CustomPeriodAutoBudgets::usesCustomPeriods($user) || !CustomPeriodAutoBudgets::handles($autoBudget)) {
            return;
        }
        Log::debug(sprintf('ext: budget #%d was created with auto-budget #%d on custom periods; its first limit #%d follows them.', $autoBudget->budget_id, $autoBudget->id, $limit->id));
        app(CustomPeriodAutoBudgets::class)->replaceInitialLimit($user, $autoBudget, $limit, today(config('app.timezone')));
    }

    /**
     * What upstream's store() makes: not generated, the auto-budget's currency and amount, this calendar month.
     */
    private function isInitialLimit(AutoBudget $autoBudget, BudgetLimit $limit): bool
    {
        $today = today(config('app.timezone'));

        return !$limit->generated
            && (int) $limit->transaction_currency_id === (int) $autoBudget->transaction_currency_id
            && 0 === bccomp((string) $limit->amount, (string) $autoBudget->amount)
            && $limit->start_date->format('Y-m-d') === (clone $today)->startOfMonth()->format('Y-m-d')
            && $limit->end_date->format('Y-m-d') === (clone $today)->endOfMonth()->format('Y-m-d');
    }
}
