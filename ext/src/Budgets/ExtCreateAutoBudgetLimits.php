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
 * - Users whose view range is not CP: exactly upstream (parent::handle()).
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
        if (!CustomPeriodAutoBudgets::usesCustomPeriods($this->extUser)) {
            parent::handle();

            return;
        }
        Log::debug(sprintf('ext: user #%d uses custom periods; monthly auto-budgets follow them.', $this->extUser->id));
        $date        = $this->extDate ?? today(config('app.timezone'));
        $custom      = app(CustomPeriodAutoBudgets::class);
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
    }
}
