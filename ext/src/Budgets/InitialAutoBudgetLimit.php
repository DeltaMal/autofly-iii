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
use FireflyIII\Support\Facades\Navigation;
use Illuminate\Support\Facades\Log;

/**
 * Hooks on upstream's auto-budget creation and editing (ext/docs/01-custom-periods.md, "Auto-budgets").
 *
 * Upstream's BudgetRepository::store() saves the auto-budget and right after it stores a first limit for the
 * auto-budget's current period with the auto-budget amount (no event in between, and nothing marks that limit).
 * ExtServiceProvider wires three hooks:
 * - autoBudgetCreated() (Eloquent "created" on AutoBudget): when the budget itself was just created, remember it
 *   and wait for that first limit; otherwise an existing budget gained an auto-budget (upstream's update path),
 *   which counts as an edit.
 * - limitCreated() (the CreatedBudgetLimit event) recognises the very next limit of a remembered budget as the
 *   one auto-budget creation made. It is recorded in ext_auto_budget_limits for every user (AutoBudgetLimitRegistry),
 *   whatever the view range or options, so a later switch to CP treats it as auto-budget-made. For a user whose
 *   monthly auto-budgets follow CP, with option "new_budget_immediate", it then becomes the current cycle's limit
 *   (CustomPeriodAutoBudgets::replaceInitialLimit()).
 * - autoBudgetUpdated() (Eloquent "updated" on AutoBudget): amount, type, period or currency changed. With option
 *   "edit_immediate" on CP, the current cycle's limit is created or adjusted at once (applyEdit()); upstream's
 *   update path makes no limit at all.
 * Bound as a singleton (one per request or job).
 */
final class InitialAutoBudgetLimit
{
    private const array EDIT_FIELDS = ['amount', 'auto_budget_type', 'period', 'transaction_currency_id'];

    /** @var array<int, int> budget id => auto-budget id, waiting for the first limit */
    private array $pending = [];

    public function autoBudgetCreated(AutoBudget $autoBudget): void
    {
        // store() associates the budget it has just created; the update path only sets budget_id
        if ($autoBudget->relationLoaded('budget') && true === $autoBudget->budget?->wasRecentlyCreated) {
            $this->pending[(int) $autoBudget->budget_id] = (int) $autoBudget->id;

            return;
        }
        $this->edited($autoBudget);
    }

    public function autoBudgetUpdated(AutoBudget $autoBudget): void
    {
        if ($autoBudget->wasChanged(self::EDIT_FIELDS)) {
            $this->edited($autoBudget);
        }
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
        AutoBudgetLimitRegistry::record($limit, 'store');
        $user         = $autoBudget->budget->user;
        if (!CustomPeriodAutoBudgets::aligns($user) || !CustomPeriodAutoBudgets::options($user)->newBudgetImmediate || !CustomPeriodAutoBudgets::handles($autoBudget)) {
            return;
        }
        Log::debug(sprintf('ext: budget #%d was created with auto-budget #%d on custom periods; its first limit #%d follows them.', $autoBudget->budget_id, $autoBudget->id, $limit->id));
        $custom       = app(CustomPeriodAutoBudgets::class);
        $custom->replaceInitialLimit($user, $autoBudget, $limit, today(config('app.timezone')));
        AutoBudgetStatus::recordChange($user, 'new_budget', $custom->changes());
    }

    private function edited(AutoBudget $autoBudget): void
    {
        $budget = $autoBudget->budget()->first();
        if (null === $budget) {
            return;
        }
        $autoBudget->setRelation('budget', $budget);
        $user   = $budget->user;
        if (!CustomPeriodAutoBudgets::aligns($user) || !CustomPeriodAutoBudgets::options($user)->editImmediate || !CustomPeriodAutoBudgets::handles($autoBudget)) {
            return;
        }
        Log::debug(sprintf('ext: auto-budget #%d of budget #%d changed on custom periods; the current cycle follows at once.', $autoBudget->id, $budget->id));
        $custom = app(CustomPeriodAutoBudgets::class);
        $custom->applyEdit($user, $autoBudget, today(config('app.timezone')));
        AutoBudgetStatus::recordChange($user, 'edit', $custom->changes());
    }

    /**
     * What upstream's store() makes: not generated, the auto-budget's currency and amount, and the auto-budget
     * period that contains today (Navigation::startOfPeriod()/endOfPeriod(), exactly as store() computes it).
     */
    private function isInitialLimit(AutoBudget $autoBudget, BudgetLimit $limit): bool
    {
        $today = today(config('app.timezone'));
        $start = Navigation::startOfPeriod($today, $autoBudget->period);
        $end   = Navigation::endOfPeriod($start, $autoBudget->period);

        return !$limit->generated
            && (int) $limit->transaction_currency_id === (int) $autoBudget->transaction_currency_id
            && 0 === bccomp((string) $limit->amount, (string) $autoBudget->amount)
            && $limit->start_date->format('Y-m-d') === $start->format('Y-m-d')
            && $limit->end_date->format('Y-m-d') === $end->format('Y-m-d');
    }
}
