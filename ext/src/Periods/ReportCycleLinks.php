<?php

/*
 * ReportCycleLinks.php
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

namespace FireflyIII\Ext\Periods;

use Carbon\Carbon;
use FireflyIII\Support\Facades\Preferences;

/**
 * Cycle quick links on upstream's reports page (ext::reports.cycle-links, included by one hook line next to the
 * year / quarter / month links): every custom period from the one containing the first transaction up to the
 * current one, grouped by the year it starts in, oldest first like upstream's months. Empty unless the user's
 * view range is CP.
 */
final class ReportCycleLinks
{
    /**
     * @return array<int, list<array{label: string, start: string, end: string}>> year => cycles (dates as Ymd)
     */
    public static function grouped(Carbon $first, ?Carbon $today = null): array
    {
        if (ExtNavigation::KEY !== Preferences::get('viewRange', '1M')->data) {
            return [];
        }
        $calendar   = ExtNavigation::calendar();
        $navigation = app('navigation');
        $today    ??= today(config('app.timezone'));
        [$cursor]   = $calendar->periodFor(($first->lt($today) ? $first : $today)->copy());
        $groups     = [];
        while ($cursor->lte($today)) {
            [$start, $end]            = $calendar->periodFor($cursor);
            $groups[$start->year][]   = [
                'label' => $navigation->periodShow($start, ExtNavigation::KEY),
                'start' => $start->format('Ymd'),
                'end'   => $end->format('Ymd'),
            ];
            $cursor                   = $end->copy()->addDay()->startOfDay();
        }

        return $groups;
    }
}
