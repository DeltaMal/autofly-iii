<?php

/*
 * ExtCreateAutoBudgetLimits.php
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
use FireflyIII\Jobs\CreateAutoBudgetLimits;
use FireflyIII\Models\AutoBudget;
use FireflyIII\User;
use Illuminate\Support\Facades\Log;

/**
 * Upstream's auto-budget job, plus custom periods. ExtServiceProvider binds it in place of
 * CreateAutoBudgetLimits, which AutoBudgetCronjob resolves with app().
 *
 * - Users who turned the option "enabled" off: exactly upstream (parent::handle()), even on CP.
 * - Users whose view range is not CP: exactly upstream (parent::handle()), plus cutting back limits left over
 *   from custom periods (option "cut_on_leave") and filling the gap after a cut cycle (option "gap_fill"); both
 *   only ever start from auto-budget-made limits that are not calendar months, so users who never used CP see
 *   no difference.
 * - CP users: monthly auto-budgets of active budgets get their limits on custom period boundaries
 *   (CustomPeriodAutoBudgets), and upstream never makes calendar-month limits for them. Every other
 *   auto-budget goes through upstream's own handleAutoBudget().
 */
class ExtCreateAutoBudgetLimits extends CreateAutoBudgetLimits
{
    private ?Carbon $extDate = null;
    private User $extUser;

    public function __construct(?Carbon $date)
    {
        parent::__construct($date);
        if ($date instanceof Carbon) {
            $this->extDate = (clone $date)->startOfDay();
        }
    }

    public function setDate(Carbon $date): void
    {
        parent::setDate($date);
        $this->extDate = (clone $date)->startOfDay();
    }

    public function setUser(User $user): void
    {
        parent::setUser($user);
        $this->extUser = $user;
    }

    public function handle(): void
    {
        $date        = $this->extDate ?? today(config('app.timezone'));
        $options     = CustomPeriodAutoBudgets::options($this->extUser);
        if (!$options->enabled) {
            // "Align monthly auto-budgets with my custom period" is off: upstream, even on CP
            parent::handle();
            AutoBudgetStatus::recordJob($this->extUser, now(config('app.timezone')), ['created' => 0, 'adjusted' => 0, 'cut' => 0, 'removed' => 0], true);

            return;
        }
        $custom      = app(CustomPeriodAutoBudgets::class);
        if (!CustomPeriodAutoBudgets::usesCustomPeriods($this->extUser)) {
            // a user who left CP: no custom period limit may run past this month or into upstream's calendar limits
            if ($options->cutOnLeave) {
                $custom->cutCurrentCycles($this->extUser, $date);
            }
            parent::handle();
            if ($options->cutOnLeave) {
                $custom->resolveOverlaps($this->extUser);
            }
            $custom->fillGaps($this->extUser, $date, $options->gapFill);
            AutoBudgetStatus::recordJob($this->extUser, now(config('app.timezone')), $custom->changes());

            return;
        }
        Log::debug(sprintf('ext: user #%d uses custom periods; monthly auto-budgets follow them.', $this->extUser->id));
        $upstream    = \Closure::bind(function (AutoBudget $autoBudget): void {
            $this->handleAutoBudget($autoBudget);
        }, $this, CreateAutoBudgetLimits::class);

        // the same query as upstream's handle()
        $autoBudgets = AutoBudget::leftJoin('budgets', 'auto_budgets.budget_id', '=', 'budgets.id')->where('budgets.user_id', $this->extUser->id)->get([
            'auto_budgets.*',
        ]);
        foreach ($autoBudgets as $autoBudget) {
            if (CustomPeriodAutoBudgets::handles($autoBudget)) {
                $custom->handle($this->extUser, $autoBudget, $date);

                continue;
            }
            $upstream($autoBudget);
        }
        AutoBudgetStatus::recordJob($this->extUser, now(config('app.timezone')), $custom->changes());
    }
}
