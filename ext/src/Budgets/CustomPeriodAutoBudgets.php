<?php

/*
 * CustomPeriodAutoBudgets.php
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

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Periods\PeriodCalendar;
use FireflyIII\Ext\Periods\PeriodSettings;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Monthly auto-budgets on custom period boundaries, for users whose view range is CP
 * (ext/docs/01-custom-periods.md, "Auto-budgets").
 *
 * The amounts follow upstream's CreateAutoBudgetLimits exactly, with "month" read as "custom period":
 * - reset:    the auto-budget amount;
 * - rollover: amount + what was left of the previous period's limit (if that was positive);
 * - adjusted: previous limit + amount + spent in the previous period (spent is negative), never above that,
 *             and at least 1 when overspending ate it all.
 * Without a limit on exactly the previous custom period, rollover and adjusted fall back to the plain amount.
 *
 * Idempotent: a limit on exactly the current period (any currency, like upstream's lookup) means nothing to do.
 */
final class CustomPeriodAutoBudgets
{
    /** @var array<int, PeriodCalendar> user id => calendar */
    private array $calendars = [];

    public static function usesCustomPeriods(User $user): bool
    {
        return ExtNavigation::KEY === (Preferences::getForUser($user, 'viewRange', '1M')?->data ?? '1M');
    }

    public static function handles(AutoBudget $autoBudget): bool
    {
        return 'monthly' === $autoBudget->period && null !== $autoBudget->budget && true === (bool) $autoBudget->budget->active;
    }

    public function calendarFor(User $user): PeriodCalendar
    {
        if (!array_key_exists($user->id, $this->calendars)) {
            $data                        = Preferences::getForUser($user, ExtNavigation::PREFERENCE, PeriodSettings::defaults()->toArray())?->data;
            $this->calendars[$user->id] = new PeriodCalendar(PeriodSettings::fromArray($data));
        }

        return $this->calendars[$user->id];
    }

    /**
     * Makes sure the custom period containing $date has a limit for this monthly auto-budget.
     */
    public function handle(User $user, AutoBudget $autoBudget, Carbon $date): void
    {
        $calendar      = $this->calendarFor($user);
        [$start, $end] = $calendar->periodFor($date);
        $budget        = $autoBudget->budget;

        if ($this->exactLimit($autoBudget, $start, $end) instanceof BudgetLimit) {
            Log::debug(sprintf('ext: auto-budget #%d already has a limit for %s - %s.', $autoBudget->id, $start->format('Y-m-d'), $end->format('Y-m-d')));

            return;
        }

        $this->takeOverCalendarLimits($user, $autoBudget, $start, $end);

        $overlap = $budget->budgetlimits()
            ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
            ->where('budget_limits.start_date', '<=', $end->format('Y-m-d'))
            ->where('budget_limits.end_date', '>=', $start->format('Y-m-d'))
            ->count()
        ;
        if ($overlap > 0) {
            Log::warning(sprintf(
                'ext: auto-budget #%d: %d other limit(s) overlap the custom period %s - %s, so no limit was created.',
                $autoBudget->id,
                $overlap,
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ));

            return;
        }

        $amount = match ((int) $autoBudget->auto_budget_type) {
            AutoBudgetType::AUTO_BUDGET_ROLLOVER->value => $this->rolloverAmount($user, $autoBudget, $calendar, $start),
            AutoBudgetType::AUTO_BUDGET_ADJUSTED->value => $this->adjustedAmount($user, $autoBudget, $calendar, $start),
            default                                     => (string) $autoBudget->amount,
        };

        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $limit      = $repository->store([
            'currency_id' => $autoBudget->transaction_currency_id,
            'budget_id'   => $budget->id,
            'start_date'  => clone $start,
            'end_date'    => clone $end,
            'amount'      => $amount,
            'period'      => $autoBudget->period,
            'generated'   => true,
        ]);
        Log::info(sprintf('ext: auto-budget #%d: limit #%d of %s for %s - %s.', $autoBudget->id, $limit->id, $amount, $start->format('Y-m-d'), $end->format('Y-m-d')));
    }

    private function exactLimit(AutoBudget $autoBudget, Carbon $start, Carbon $end): ?BudgetLimit
    {
        /** @var null|BudgetLimit */
        return $autoBudget->budget->budgetlimits()
            ->where('budget_limits.start_date', $start->format('Y-m-d'))
            ->where('budget_limits.end_date', $end->format('Y-m-d'))
            ->first(['budget_limits.*'])
        ;
    }

    /**
     * Switching to CP mid-month: calendar-month limits that upstream made for this auto-budget (generated by the
     * cron, or the first limit made when the budget was created, which has the auto-budget amount) and that
     * overlap the current custom period are cut back to end the day before it starts, or removed when they
     * start inside it. Limits the user set by hand are never touched.
     */
    private function takeOverCalendarLimits(User $user, AutoBudget $autoBudget, Carbon $start, Carbon $end): void
    {
        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $limits     = $autoBudget->budget->budgetlimits()
            ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
            ->where('budget_limits.start_date', '<=', $end->format('Y-m-d'))
            ->where('budget_limits.end_date', '>=', $start->format('Y-m-d'))
            ->get(['budget_limits.*'])
        ;

        /** @var BudgetLimit $limit */
        foreach ($limits as $limit) {
            if (!self::isCalendarMonth($limit) || (!$limit->generated && 0 !== bccomp((string) $limit->amount, (string) $autoBudget->amount))) {
                continue;
            }
            $limitStart = Carbon::parse($limit->start_date->format('Y-m-d'), $start->getTimezone());
            if ($limitStart->lt($start)) {
                $newEnd = (clone $start)->subDay()->endOfDay();
                Log::info(sprintf('ext: auto-budget #%d: calendar limit #%d now ends %s (custom periods take over).', $autoBudget->id, $limit->id, $newEnd->format('Y-m-d')));
                $repository->update($limit, ['end' => $newEnd, 'fire_webhooks' => false]);

                continue;
            }
            Log::info(sprintf('ext: auto-budget #%d: calendar limit #%d removed (custom periods take over).', $autoBudget->id, $limit->id));
            $repository->destroyBudgetLimit($limit);
        }
    }

    private static function isCalendarMonth(BudgetLimit $limit): bool
    {
        $first = $limit->start_date;
        $last  = $limit->end_date;

        return 1 === (int) $first->format('j')
            && $first->format('Y-m') === $last->format('Y-m')
            && (int) $last->format('j') === (int) $last->format('t');
    }

    /**
     * @return array{0: BudgetLimit, 1: string}|null the previous period's exact limit and what was spent in it
     */
    private function previous(User $user, AutoBudget $autoBudget, PeriodCalendar $calendar, Carbon $start): ?array
    {
        [$previousStart, $previousEnd] = $calendar->periodFor((clone $start)->subDay());
        $limit                         = $this->exactLimit($autoBudget, $previousStart, $previousEnd);
        if (!$limit instanceof BudgetLimit) {
            Log::debug(sprintf('ext: auto-budget #%d: no limit for the previous period %s - %s.', $autoBudget->id, $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')));

            return null;
        }
        $repository = app(OperationsRepositoryInterface::class);
        $repository->setUser($user);
        $spent      = $repository->sumExpenses($previousStart, $previousEnd, null, new Collection()->push($autoBudget->budget), $autoBudget->transactionCurrency);

        return [$limit, (string) ($spent[$autoBudget->transaction_currency_id]['sum'] ?? '0')];
    }

    private function rolloverAmount(User $user, AutoBudget $autoBudget, PeriodCalendar $calendar, Carbon $start): string
    {
        $amount   = (string) $autoBudget->amount;
        $previous = $this->previous($user, $autoBudget, $calendar, $start);
        if (null === $previous) {
            return $amount;
        }
        $left = bcadd((string) $previous[0]->amount, $previous[1]);

        // upstream: a negative remainder resets to the amount, a positive one is added to it
        return 1 === bccomp('0', $left) ? $amount : bcadd($left, $amount);
    }

    private function adjustedAmount(User $user, AutoBudget $autoBudget, PeriodCalendar $calendar, Carbon $start): string
    {
        $amount   = (string) $autoBudget->amount;
        $previous = $this->previous($user, $autoBudget, $calendar, $start);
        if (null === $previous) {
            return $amount;
        }
        $available = bcadd(bcadd((string) $previous[0]->amount, $amount), $previous[1]);

        // upstream: whatever is available (more or less than the amount); "1" when overspending ate it all
        return 1 === bccomp($available, '0') ? $available : '1';
    }
}
