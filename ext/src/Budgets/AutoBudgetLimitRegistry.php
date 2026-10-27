<?php

/*
 * AutoBudgetLimitRegistry.php
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

use FireflyIII\Models\BudgetLimit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The ext_auto_budget_limits table: budget limits that upstream's auto-budget creation made. Upstream stores a
 * budget's first limit without marking it generated; ext remembers it here (for every user, whatever the view
 * range or options), so a later switch to custom periods treats it as auto-budget-made (ext/docs/01).
 */
final class AutoBudgetLimitRegistry
{
    public const string TABLE = 'ext_auto_budget_limits';

    public static function record(BudgetLimit $limit, string $source): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'budget_limit_id' => $limit->id,
            'source'          => $source,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public static function isRecorded(BudgetLimit $limit): bool
    {
        return DB::table(self::TABLE)->where('budget_limit_id', $limit->id)->exists();
    }

    /**
     * For whereIn('budget_limits.id', ...).
     */
    public static function ids(): Builder
    {
        return DB::table(self::TABLE)->select('budget_limit_id');
    }

    /**
     * Auto-budget-made: generated (upstream's cron or the ext job) or recorded here.
     */
    public static function isAutoBudgetMade(BudgetLimit $limit): bool
    {
        return true === (bool) $limit->generated || self::isRecorded($limit);
    }
}
