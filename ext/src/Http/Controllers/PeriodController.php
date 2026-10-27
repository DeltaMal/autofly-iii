<?php

/*
 * PeriodController.php
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

namespace FireflyIII\Ext\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Periods\PeriodCalendar;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /ext/periods: the current custom period and the next six, the explicit starts, and "Close current period".
 */
final class PeriodController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->middleware(static function ($request, $next) {
            app('view')->share('title', (string) trans('ext::periods.title'));
            app('view')->share('mainTitleIcon', 'bi-calendar-range');

            return $next($request);
        });
    }

    public function index(): View
    {
        $settings  = ExtNavigation::settings();
        $calendar  = ExtNavigation::calendar();
        $periods   = [];
        $cursor    = today(config('app.timezone'));
        for ($i = 0; $i < 7; ++$i) {
            [$start, $end] = $calendar->periodFor($cursor);
            $periods[]     = [
                'start' => $start,
                'end'   => $end,
                'days'  => (int) $start->diffInDays($end->copy()->startOfDay(), true) + 1,
                'label' => Navigation::periodShow($start, ExtNavigation::KEY),
            ];
            $cursor        = $end->copy()->addDay()->startOfDay();
        }
        $starts    = $settings->starts;
        usort($starts, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return view('ext::periods.index', [
            'settings'       => $settings,
            'periods'        => $periods,
            'starts'         => $starts,
            'active'         => ExtNavigation::KEY === Navigation::getViewRange(false),
            'today'          => today(config('app.timezone'))->format('Y-m-d'),
            'extBreadcrumbs' => [(string) trans('ext::periods.title') => route('ext.periods.index')],
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data     = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        $settings = ExtNavigation::settings();
        $starts   = array_values(array_filter($settings->starts, static fn (array $item): bool => $item['date'] !== $data['date']));
        $name     = trim((string) ($data['name'] ?? ''));
        $starts[] = '' === $name ? ['date' => $data['date']] : ['date' => $data['date'], 'name' => $name];
        usort($starts, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        $this->save($settings->withStarts($starts)->toArray());
        session()->flash('success', (string) trans('ext::periods.closed', ['date' => Carbon::createFromFormat('Y-m-d', $data['date'])->isoFormat((string) trans('ext::periods.format_day_month_year'))]));

        return redirect(route('ext.periods.index'));
    }

    public function deleteStart(Request $request): RedirectResponse
    {
        $data     = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $settings = ExtNavigation::settings();
        $starts   = array_filter($settings->starts, static fn (array $item): bool => $item['date'] !== $data['date']);
        $this->save($settings->withStarts($starts)->toArray());
        session()->flash('success', (string) trans('ext::periods.start_deleted', ['date' => $data['date']]));

        return redirect(route('ext.periods.index'));
    }

    /**
     * Saves the settings and clears the session range so the new boundaries apply on the next page.
     */
    private function save(array $settings): void
    {
        PeriodCalendar::fromArray($settings); // throws on nonsense before anything is stored
        Preferences::set(ExtNavigation::PREFERENCE, $settings);
        session()->forget(['start', 'end', 'range']);
    }
}
