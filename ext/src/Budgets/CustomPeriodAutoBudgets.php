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
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 *
 * Every behaviour is a per-user option (AutoBudgetOptions, shown on /ext/periods); callers check the options
 * through aligns() and options(). "Auto-budget-made" limits are generated ones plus the ones upstream's budget
 * creation made (AutoBudgetLimitRegistry). Any other limit is the user's and is never touched.
 * Each instance counts what it changed (changes()), for the status on /ext/periods.
 */
final class CustomPeriodAutoBudgets
{
    /** @var array<int, PeriodCalendar> user id => calendar */
    private array $calendars = [];

    /** @var array{created: int, adjusted: int, cut: int, removed: int} */
    private array $changes   = ['created' => 0, 'adjusted' => 0, 'cut' => 0, 'removed' => 0];

    public static function usesCustomPeriods(User $user): bool
    {
        return ExtNavigation::KEY === (Preferences::getForUser($user, 'viewRange', '1M')?->data ?? '1M');
    }

    public static function options(User $user): AutoBudgetOptions
    {
        return PeriodSettings::fromArray(Preferences::getForUser($user, ExtNavigation::PREFERENCE, PeriodSettings::defaults()->toArray())?->data)->autoBudgets;
    }

    /**
     * On CP, with "Align monthly auto-budgets with my custom period" on.
     */
    public static function aligns(User $user): bool
    {
        return self::usesCustomPeriods($user) && self::options($user)->enabled;
    }

    /**
     * @return array{created: int, adjusted: int, cut: int, removed: int}
     */
    public function changes(): array
    {
        return $this->changes;
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

        if (self::options($user)->takeOver) {
            $this->takeOverGeneratedLimits($user, $autoBudget, $start, $end);
        }

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

        $amount = $this->amountFor($user, $autoBudget, $calendar, $start);

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
        ++$this->changes['created'];
        Log::info(sprintf('ext: auto-budget #%d: limit #%d of %s for %s - %s.', $autoBudget->id, $limit->id, $amount, $start->format('Y-m-d'), $end->format('Y-m-d')));
    }

    /**
     * An auto-budget was added to an existing budget, or its amount, type, period or currency changed, while on
     * CP (option "edit_immediate"): the current cycle's limit is adjusted to what the job would make now, or
     * created (as in handle()) when there is none. A limit on the cycle that is not auto-budget-made, or is in
     * another currency, stays as it is.
     */
    public function applyEdit(User $user, AutoBudget $autoBudget, Carbon $date): void
    {
        $calendar      = $this->calendarFor($user);
        [$start, $end] = $calendar->periodFor($date);
        $exact         = $this->exactLimit($autoBudget, $start, $end);
        if (!$exact instanceof BudgetLimit) {
            $this->handle($user, $autoBudget, $date);

            return;
        }
        if (!AutoBudgetLimitRegistry::isAutoBudgetMade($exact) || (int) $exact->transaction_currency_id !== (int) $autoBudget->transaction_currency_id) {
            Log::info(sprintf('ext: auto-budget #%d changed, but limit #%d on the custom period is not one it made (or is in another currency), so it stays.', $autoBudget->id, $exact->id));

            return;
        }
        $amount        = $this->amountFor($user, $autoBudget, $calendar, $start);
        if (0 === bccomp((string) $exact->amount, $amount)) {
            return;
        }
        $repository    = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $repository->update($exact, ['amount' => $amount, 'fire_webhooks' => false]);
        ++$this->changes['adjusted'];
        Log::info(sprintf('ext: auto-budget #%d changed: limit #%d is now %s for %s - %s.', $autoBudget->id, $exact->id, $amount, $start->format('Y-m-d'), $end->format('Y-m-d')));
    }

    /**
     * A budget created with an auto-budget while on CP: upstream's BudgetRepository::store() has just made its
     * first calendar-month limit ($initial). It becomes the current custom period's limit at once, with the same
     * amount rules as the cron, and is marked generated like every other limit an auto-budget makes. Other
     * generated limits are taken over as in handle(); if a limit of the user's overlaps, $initial stays as it is.
     */
    public function replaceInitialLimit(User $user, AutoBudget $autoBudget, BudgetLimit $initial, Carbon $date): void
    {
        $calendar      = $this->calendarFor($user);
        [$start, $end] = $calendar->periodFor($date);
        if (self::options($user)->takeOver) {
            $this->takeOverGeneratedLimits($user, $autoBudget, $start, $end, $initial);
        }

        $overlap       = $autoBudget->budget->budgetlimits()
            ->where('budget_limits.id', '!=', $initial->id)
            ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
            ->where('budget_limits.start_date', '<=', $end->format('Y-m-d'))
            ->where('budget_limits.end_date', '>=', $start->format('Y-m-d'))
            ->count()
        ;
        if ($overlap > 0) {
            Log::warning(sprintf('ext: auto-budget #%d: %d other limit(s) overlap the custom period %s - %s, so the first limit #%d stays as it is.', $autoBudget->id, $overlap, $start->format('Y-m-d'), $end->format('Y-m-d'), $initial->id));

            return;
        }

        $amount               = $this->amountFor($user, $autoBudget, $calendar, $start);
        $repository           = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $initial->generated   = true;
        $initial->period      = $autoBudget->period;
        $repository->update($initial, ['start' => clone $start, 'end' => clone $end, 'amount' => $amount, 'fire_webhooks' => false]);
        ++$this->changes['adjusted'];
        Log::info(sprintf('ext: auto-budget #%d: first limit #%d is now %s for %s - %s.', $autoBudget->id, $initial->id, $amount, $start->format('Y-m-d'), $end->format('Y-m-d')));
    }

    /**
     * Leaving CP for a calendar view range: the current cycle's generated limit of each monthly auto-budget is cut
     * to end on the last day of $date's calendar month, so upstream's run on the next 1st makes a calendar limit
     * that overlaps nothing. Limits of past cycles stay as they are. Idempotent.
     */
    public function cutCurrentCycles(User $user, Carbon $date): void
    {
        $monthEnd   = (clone $date)->endOfMonth();
        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        foreach (self::monthlyAutoBudgets($user) as $autoBudget) {
            $limits = $this->autoBudgetMadeLimits($autoBudget)
                ->where('budget_limits.start_date', '<=', $date->format('Y-m-d'))
                ->where('budget_limits.end_date', '>', $monthEnd->format('Y-m-d'))
                ->get(['budget_limits.*'])
            ;

            /** @var BudgetLimit $limit */
            foreach ($limits as $limit) {
                Log::info(sprintf('ext: auto-budget #%d: limit #%d now ends %s (left custom periods).', $autoBudget->id, $limit->id, $monthEnd->format('Y-m-d')));
                $repository->update($limit, ['end' => clone $monthEnd, 'fire_webhooks' => false]);
                ++$this->changes['cut'];
            }
        }
    }

    /**
     * Leftover overlap after leaving CP (e.g. the view range changed through the API, which the preferences
     * middleware does not see, and upstream's run on the 1st then made a calendar limit): a generated limit of a
     * monthly auto-budget that is not a calendar month and runs into a generated calendar-month limit of the same
     * budget is cut to end the day before that month. Idempotent.
     */
    public function resolveOverlaps(User $user): void
    {
        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        foreach (self::monthlyAutoBudgets($user) as $autoBudget) {
            $limits = $this->autoBudgetMadeLimits($autoBudget)->orderBy('budget_limits.start_date')->get(['budget_limits.*']);

            /** @var BudgetLimit $month */
            foreach ($limits->filter(static fn (BudgetLimit $l): bool => self::isCalendarMonth($l)) as $month) {
                /** @var BudgetLimit $limit */
                foreach ($limits as $limit) {
                    if (self::isCalendarMonth($limit) || !$limit->start_date->lt($month->start_date) || $limit->end_date->lt($month->start_date)) {
                        continue;
                    }
                    $newEnd = Carbon::parse($month->start_date->format('Y-m-d'), config('app.timezone'))->subDay()->endOfDay();
                    Log::info(sprintf('ext: auto-budget #%d: limit #%d now ends %s (overlapped calendar limit #%d).', $autoBudget->id, $limit->id, $newEnd->format('Y-m-d'), $month->id));
                    $repository->update($limit, ['end' => $newEnd, 'fire_webhooks' => false]);
                    ++$this->changes['cut'];
                    $limit->refresh();
                }
            }
        }
    }

    /**
     * After leaving CP (option "gap_fill"): the days from the end of the last custom period in $date's calendar
     * month to the end of that month, when no limit covers them, get a generated limit of the auto-budget amount,
     * pro rata ("pro_rata": amount x days left / days in the month, rounded to the currency's decimals) or in full
     * ("full"). Only monthly auto-budgets of active budgets (what upstream makes monthly limits for), and only
     * after a custom period that ended inside this month. If a limit already covers part of those days, only the
     * days after the last of them are filled. Idempotent.
     */
    public function fillGaps(User $user, Carbon $date, string $mode): void
    {
        if ('pro_rata' !== $mode && 'full' !== $mode) {
            return;
        }
        $monthStart = (clone $date)->startOfMonth();
        $monthEnd   = (clone $date)->endOfMonth()->startOfDay();
        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        foreach (self::monthlyAutoBudgets($user) as $autoBudget) {
            if (!self::handles($autoBudget)) {
                continue;
            }
            $cycle    = $this->autoBudgetMadeLimits($autoBudget)
                ->where('budget_limits.generated', true)
                ->where('budget_limits.end_date', '>=', $monthStart->format('Y-m-d'))
                ->where('budget_limits.end_date', '<', $monthEnd->format('Y-m-d'))
                ->get(['budget_limits.*'])
                ->reject(static fn (BudgetLimit $l): bool => self::isCalendarMonth($l))
                ->sortByDesc(static fn (BudgetLimit $l): string => $l->end_date->format('Y-m-d'))
                ->first()
            ;
            if (!$cycle instanceof BudgetLimit) {
                continue;
            }
            $gapStart = Carbon::parse($cycle->end_date->format('Y-m-d'), $date->getTimezone())->addDay();
            $covered  = $autoBudget->budget->budgetlimits()
                ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
                ->where('budget_limits.start_date', '<=', $monthEnd->format('Y-m-d'))
                ->where('budget_limits.end_date', '>=', $gapStart->format('Y-m-d'))
                ->max('budget_limits.end_date')
            ;
            if (null !== $covered) {
                $gapStart = Carbon::parse(substr((string) $covered, 0, 10), $date->getTimezone())->addDay();
            }
            if ($gapStart->gt($monthEnd)) {
                continue;
            }
            $days     = (int) $gapStart->diffInDays($monthEnd, true) + 1;
            $amount   = 'full' === $mode ? (string) $autoBudget->amount : self::proRata((string) $autoBudget->amount, $days, (int) $monthEnd->format('t'), $autoBudget->transactionCurrency);
            $limit    = $repository->store([
                'currency_id' => $autoBudget->transaction_currency_id,
                'budget_id'   => $autoBudget->budget_id,
                'start_date'  => clone $gapStart,
                'end_date'    => (clone $monthEnd)->endOfDay(),
                'amount'      => $amount,
                'period'      => 'monthly',
                'generated'   => true,
            ]);
            ++$this->changes['created'];
            Log::info(sprintf('ext: auto-budget #%d: gap limit #%d of %s for %s - %s (%s).', $autoBudget->id, $limit->id, $amount, $gapStart->format('Y-m-d'), $monthEnd->format('Y-m-d'), $mode));
        }
    }

    /**
     * amount x days / daysInMonth, rounded half up to the currency's decimals (amounts are positive).
     */
    public static function proRata(string $amount, int $days, int $daysInMonth, ?TransactionCurrency $currency): string
    {
        $decimals = null === $currency ? 2 : (int) $currency->decimal_places;
        $exact    = bcdiv(bcmul($amount, (string) $days, 12), (string) $daysInMonth, 12);
        $half     = 0 === $decimals ? '0.5' : '0.'.str_repeat('0', $decimals).'5';

        return bcadd($exact, $half, $decimals);
    }

    /**
     * @return Collection<int, AutoBudget>
     */
    private static function monthlyAutoBudgets(User $user): Collection
    {
        return AutoBudget::leftJoin('budgets', 'auto_budgets.budget_id', '=', 'budgets.id')
            ->where('budgets.user_id', $user->id)
            ->where('auto_budgets.period', 'monthly')
            ->get(['auto_budgets.*'])
        ;
    }

    /**
     * Limits of this auto-budget's budget and currency that a monthly auto-budget made: generated monthly limits
     * (upstream's cron, the ext job) and the first limits upstream's budget creation made (the registry).
     */
    private function autoBudgetMadeLimits(AutoBudget $autoBudget): HasMany
    {
        return $autoBudget->budget->budgetlimits()
            ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
            ->where(static function ($query): void {
                $query->where(static fn ($generated) => $generated->where('budget_limits.generated', true)->where('budget_limits.period', 'monthly'))
                    ->orWhereIn('budget_limits.id', AutoBudgetLimitRegistry::ids())
                ;
            })
        ;
    }

    private static function isCalendarMonth(BudgetLimit $limit): bool
    {
        $first = $limit->start_date;
        $last  = $limit->end_date;

        return 1 === (int) $first->format('j')
            && $first->format('Y-m') === $last->format('Y-m')
            && (int) $last->format('j') === (int) $last->format('t');
    }

    private function amountFor(User $user, AutoBudget $autoBudget, PeriodCalendar $calendar, Carbon $start): string
    {
        return match ((int) $autoBudget->auto_budget_type) {
            AutoBudgetType::AUTO_BUDGET_ROLLOVER->value => $this->rolloverAmount($user, $autoBudget, $calendar, $start),
            AutoBudgetType::AUTO_BUDGET_ADJUSTED->value => $this->adjustedAmount($user, $autoBudget, $calendar, $start),
            default                                     => (string) $autoBudget->amount,
        };
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
     * Switching to CP mid-month (option "take_over"): limits that an auto-budget made for this budget (generated
     * by the cron, ext's or upstream's, or recorded as upstream's first limit) and that overlap the current custom
     * period are cut back to end the day before it starts, or removed when they start inside it. Any other limit
     * is the user's and is never touched, even when its amount happens to equal the auto-budget amount.
     */
    private function takeOverGeneratedLimits(User $user, AutoBudget $autoBudget, Carbon $start, Carbon $end, ?BudgetLimit $except = null): void
    {
        $repository = app(BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $limits     = $autoBudget->budget->budgetlimits()
            ->where('budget_limits.transaction_currency_id', $autoBudget->transaction_currency_id)
            ->where('budget_limits.start_date', '<=', $end->format('Y-m-d'))
            ->where('budget_limits.end_date', '>=', $start->format('Y-m-d'))
            ->where(static fn ($query) => $query->where('budget_limits.generated', true)->orWhereIn('budget_limits.id', AutoBudgetLimitRegistry::ids()))
            ->get(['budget_limits.*'])
        ;

        /** @var BudgetLimit $limit */
        foreach ($limits as $limit) {
            if ($except instanceof BudgetLimit && $except->id === $limit->id) {
                continue;
            }
            $limitStart = Carbon::parse($limit->start_date->format('Y-m-d'), $start->getTimezone());
            if ($limitStart->lt($start)) {
                $newEnd = (clone $start)->subDay()->endOfDay();
                Log::info(sprintf('ext: auto-budget #%d: generated limit #%d now ends %s (custom periods take over).', $autoBudget->id, $limit->id, $newEnd->format('Y-m-d')));
                $repository->update($limit, ['end' => $newEnd, 'fire_webhooks' => false]);
                ++$this->changes['cut'];

                continue;
            }
            Log::info(sprintf('ext: auto-budget #%d: generated limit #%d removed (custom periods take over).', $autoBudget->id, $limit->id));
            $repository->destroyBudgetLimit($limit);
            ++$this->changes['removed'];
        }
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
