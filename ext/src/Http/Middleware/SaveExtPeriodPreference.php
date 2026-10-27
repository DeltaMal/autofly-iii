<?php

/*
 * SaveExtPeriodPreference.php
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

namespace FireflyIII\Ext\Http\Middleware;

use Closure;
use FireflyIII\Ext\Budgets\AutoBudgetStatus;
use FireflyIII\Ext\Budgets\CustomPeriodAutoBudgets;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Periods\PeriodSettings;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Saves the extPeriod preference when upstream's preferences form is posted (the fields come from the
 * ext::preferences.view-range partial). Upstream's PreferencesController saves viewRange itself and stays
 * unedited. Pushed onto the user-full-auth group by ExtServiceProvider.
 *
 * When that post moves the view range from CP to anything else, the current cycle's auto-budget limits are cut
 * to end with this calendar month (CustomPeriodAutoBudgets::cutCurrentCycles()), so upstream's run on the 1st
 * makes a calendar limit that overlaps nothing (option "cut_on_leave"), and the days a cycle that ended earlier
 * leaves uncovered up to the next 1st get a limit (option "gap_fill"). Nothing happens when the user turned the
 * auto-budget job's "enabled" option off. Changes through the API are caught by the daily auto-budget job.
 */
final class SaveExtPeriodPreference
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user      = $request->user();
        if ('POST' !== $request->method() || 'preferences.update' !== $request->route()?->getName() || !$user instanceof User) {
            return $next($request);
        }
        $wasCustom = CustomPeriodAutoBudgets::usesCustomPeriods($user);
        $this->saveSettings($request);
        $response  = $next($request);
        $options   = CustomPeriodAutoBudgets::options($user);
        if ($wasCustom && $options->enabled && !CustomPeriodAutoBudgets::usesCustomPeriods($user)) {
            $custom = app(CustomPeriodAutoBudgets::class);
            $today  = today(config('app.timezone'));
            if ($options->cutOnLeave) {
                $custom->cutCurrentCycles($user, $today);
            }
            $custom->fillGaps($user, $today, $options->gapFill);
            AutoBudgetStatus::recordChange($user, 'preferences', $custom->changes());
        }

        return $response;
    }

    private function saveSettings(Request $request): void
    {
        if (!$request->has('ext_period')) {
            return;
        }
        $input     = (array) $request->input('ext_period', []);
        $validator = Validator::make($input, [
            'anchor_day'        => ['nullable', 'integer', 'between:1,31'],
            'business_day_rule' => ['required', Rule::in(PeriodSettings::BUSINESS_DAY_RULES)],
            'label'             => ['required', Rule::in(PeriodSettings::LABEL_STYLES)],
        ]);
        if ($validator->fails()) {
            session()->flash('error', (string) trans('ext::periods.invalid_settings', ['errors' => implode(' ', $validator->errors()->all())]));

            return;
        }
        $current   = ExtNavigation::settings();
        $settings  = new PeriodSettings(
            null === ($input['anchor_day'] ?? null) || '' === $input['anchor_day'] ? null : (int) $input['anchor_day'],
            (string) $input['business_day_rule'],
            (string) $input['label'],
            $current->starts, // explicit starts and the auto-budget options are managed on /ext/periods
            $current->autoBudgets,
        );
        Preferences::set(ExtNavigation::PREFERENCE, $settings->toArray());
    }
}
