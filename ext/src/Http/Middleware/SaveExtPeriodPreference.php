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
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Periods\PeriodSettings;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Saves the extPeriod preference when upstream's preferences form is posted (the fields come from the
 * ext::preferences.view-range partial). Upstream's PreferencesController saves viewRange itself and stays
 * unedited. Pushed onto the user-full-auth group by ExtServiceProvider.
 */
final class SaveExtPeriodPreference
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ('POST' !== $request->method() || 'preferences.update' !== $request->route()?->getName() || !$request->has('ext_period') || null === $request->user()) {
            return $next($request);
        }
        $input     = (array) $request->input('ext_period', []);
        $validator = Validator::make($input, [
            'anchor_day'        => ['nullable', 'integer', 'between:1,31'],
            'business_day_rule' => ['required', Rule::in(PeriodSettings::BUSINESS_DAY_RULES)],
            'label'             => ['required', Rule::in(PeriodSettings::LABEL_STYLES)],
        ]);
        if ($validator->fails()) {
            session()->flash('error', (string) trans('ext::periods.invalid_settings', ['errors' => implode(' ', $validator->errors()->all())]));

            return $next($request);
        }
        $current   = ExtNavigation::settings();
        $settings  = new PeriodSettings(
            null === ($input['anchor_day'] ?? null) || '' === $input['anchor_day'] ? null : (int) $input['anchor_day'],
            (string) $input['business_day_rule'],
            (string) $input['label'],
            $current->starts, // explicit starts are managed on /ext/periods
        );
        Preferences::set(ExtNavigation::PREFERENCE, $settings->toArray());

        return $next($request);
    }
}
