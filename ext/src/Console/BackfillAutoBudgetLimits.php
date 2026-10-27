<?php

/*
 * BackfillAutoBudgetLimits.php
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

namespace FireflyIII\Ext\Console;

use Carbon\Carbon;
use FireflyIII\Ext\Budgets\AutoBudgetLimitRegistry;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\BudgetLimit;
use Illuminate\Console\Command;

/**
 * ext:auto-budgets:backfill: a one-off, optional cut-over step (ext/docs/04). Limits that upstream's budget
 * creation made before ext recorded them are marked in ext_auto_budget_limits, but only when there is no doubt:
 * - the budget has a monthly auto-budget, created together with the budget (within a minute);
 * - the limit is not generated and is exactly the calendar month the budget was created in;
 * - it was stored together with the auto-budget (within a minute);
 * - its amount and currency equal the auto-budget's.
 * Every other limit stays the user's. Never run automatically.
 */
final class BackfillAutoBudgetLimits extends Command
{
    private const int TOGETHER_SECONDS = 60;

    protected $signature   = 'ext:auto-budgets:backfill {--dry-run : list what would be marked, mark nothing}';
    protected $description = 'Mark the first limits that upstream made for budgets created with a monthly auto-budget, so custom periods may take them over.';

    public function handle(): int
    {
        $dryRun     = (bool) $this->option('dry-run');
        $marked     = 0;
        $tz         = (string) config('app.timezone');
        $autoBudgets = AutoBudget::query()->with(['budget', 'budget.budgetlimits'])->where('period', 'monthly')->orderBy('id')->get();
        foreach ($autoBudgets as $autoBudget) {
            $budget = $autoBudget->budget;
            if (null === $budget || null === $budget->created_at || null === $autoBudget->created_at || !self::together($budget->created_at, $autoBudget->created_at)) {
                continue;
            }
            $created = Carbon::parse($budget->created_at)->setTimezone($tz);
            $start   = (clone $created)->startOfMonth()->format('Y-m-d');
            $end     = (clone $created)->endOfMonth()->format('Y-m-d');

            /** @var BudgetLimit $limit */
            foreach ($budget->budgetlimits as $limit) {
                if (true === (bool) $limit->generated
                    || $limit->start_date->format('Y-m-d') !== $start
                    || $limit->end_date->format('Y-m-d') !== $end
                    || (int) $limit->transaction_currency_id !== (int) $autoBudget->transaction_currency_id
                    || 0 !== bccomp((string) $limit->amount, (string) $autoBudget->amount)
                    || null === $limit->created_at
                    || !self::together($autoBudget->created_at, $limit->created_at)
                    || AutoBudgetLimitRegistry::isRecorded($limit)) {
                    continue;
                }
                ++$marked;
                $this->line(sprintf('%s limit #%d of budget #%d (user #%d): %s - %s, %s', $dryRun ? 'Would mark' : 'Marked', $limit->id, $budget->id, $budget->user_id, $start, $end, $limit->amount));
                if (!$dryRun) {
                    AutoBudgetLimitRegistry::record($limit, 'backfill');
                }
            }
        }
        $this->info(sprintf('%s %d limit(s) as made by an auto-budget.', $dryRun ? 'Dry run: would mark' : 'Marked', $marked));

        return self::SUCCESS;
    }

    private static function together(Carbon $first, Carbon $second): bool
    {
        return abs($first->getTimestamp() - $second->getTimestamp()) <= self::TOGETHER_SECONDS;
    }
}
