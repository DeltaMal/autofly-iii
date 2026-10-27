<?php

/*
 * ExtNavigation.php
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
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\Navigation;
use Illuminate\Support\Facades\Log;

/**
 * Upstream Navigation plus the custom period view range "CP" (ext/docs/01-custom-periods.md).
 *
 * Bound as 'navigation' by ExtServiceProvider. Every public method that takes a range or frequency answers
 * "CP" through PeriodCalendar (using the authenticated user's extPeriod preference) and otherwise returns
 * exactly what upstream returns. The distance-based helpers (preferred*Format, listOfPeriods, ...) treat a
 * span of one custom period like upstream treats one calendar month: per day.
 */
class ExtNavigation extends Navigation
{
    public const string KEY        = 'CP';
    public const string PREFERENCE = 'extPeriod';

    /** @var array<string, PeriodCalendar> settings JSON => calendar (boundaries are cached inside) */
    private static array $calendars = [];

    public static function settings(): PeriodSettings
    {
        return PeriodSettings::fromArray(Preferences::get(self::PREFERENCE, PeriodSettings::defaults()->toArray())->data);
    }

    public static function calendar(): PeriodCalendar
    {
        $settings = self::settings();
        $key      = (string) json_encode($settings->toArray());
        if (count(self::$calendars) > 20) {
            self::$calendars = [];
        }

        return self::$calendars[$key] ??= new PeriodCalendar($settings);
    }

    /**
     * Average number of custom periods per year (the periods in the year before today), as a bcmath
     * string. Used by the one-line hook in upstream's Bill\IndexController::amountPerPeriod().
     */
    public static function periodsPerYear(): string
    {
        $calendar = self::calendar();
        $today    = PeriodCalendar::dayOf(today(config('app.timezone')));
        $count    = 0;
        // period starts in (today - 365, today]
        for ($cursor = $calendar->periodDays($today - 365)[1] + 1; $cursor <= $today; $cursor = $calendar->periodDays($cursor)[1] + 1) {
            ++$count;
        }

        return (string) max(1, $count);
    }

    public function addPeriod(Carbon $theDate, string $repeatFreq, int $skip = 0): Carbon
    {
        if (self::KEY === $repeatFreq) {
            return self::calendar()->add($theDate, $skip);
        }

        return parent::addPeriod($theDate, $repeatFreq, $skip);
    }

    public function subtractPeriod(Carbon $theDate, string $repeatFreq, ?int $subtract = null): Carbon
    {
        if (self::KEY === $repeatFreq) {
            $subtract ??= 1;

            return $subtract < 1 ? clone $theDate : self::calendar()->subtract($theDate, $subtract - 1);
        }

        return parent::subtractPeriod($theDate, $repeatFreq, $subtract);
    }

    public function startOfPeriod(Carbon $theDate, string $repeatFreq): Carbon
    {
        if (self::KEY === $repeatFreq) {
            return self::calendar()->startOf($theDate);
        }

        return parent::startOfPeriod($theDate, $repeatFreq);
    }

    /**
     * Upstream returns the end of the period that starts at $end; for CP that is the end of the period
     * containing $end (the same thing when $end is a period start).
     */
    public function endOfPeriod(Carbon $end, string $repeatFreq): Carbon
    {
        if (self::KEY === $repeatFreq) {
            return self::calendar()->endOf($end);
        }

        return parent::endOfPeriod($end, $repeatFreq);
    }

    public function endOfX(Carbon $theCurrentEnd, string $repeatFreq, ?Carbon $maxDate): Carbon
    {
        if (self::KEY === $repeatFreq) {
            $end = self::calendar()->endOf($theCurrentEnd);

            return $maxDate instanceof Carbon && $end > $maxDate ? clone $maxDate : $end;
        }

        return parent::endOfX($theCurrentEnd, $repeatFreq, $maxDate);
    }

    public function updateStartDate(string $range, Carbon $start): Carbon
    {
        if (self::KEY === $range) {
            return self::calendar()->startOf($start);
        }

        return parent::updateStartDate($range, $start);
    }

    public function updateEndDate(string $range, Carbon $start): Carbon
    {
        if (self::KEY === $range) {
            return self::calendar()->endOf($start);
        }

        return parent::updateEndDate($range, $start);
    }

    public function periodShow(Carbon $theDate, string $repeatFrequency): string
    {
        if (self::KEY === $repeatFrequency) {
            $calendar      = self::calendar();
            [$start, $end] = $calendar->periodFor($theDate);

            return $calendar->label($start, $end, Steam::getLocale(), [
                'day_month'      => (string) trans('ext::periods.format_day_month'),
                'day_month_year' => (string) trans('ext::periods.format_day_month_year'),
                'month_year'     => (string) trans('ext::periods.format_month_year'),
                'range'          => (string) trans('ext::periods.format_range'),
            ]);
        }

        return parent::periodShow($theDate, $repeatFrequency);
    }

    /**
     * Upstream's algorithm already works for CP, because it steps through $this->startOfPeriod() and
     * $this->endOfPeriod(); kept as an explicit override so every range-taking method is accounted for.
     */
    public function blockPeriods(Carbon $start, Carbon $end, string $range): array
    {
        return parent::blockPeriods($start, $end, $range);
    }

    /**
     * Number of whole custom periods needed to get from $beginning to $end (rounded up, like upstream).
     */
    public function diffInPeriods(string $period, int $skip, Carbon $beginning, Carbon $end): int
    {
        if (self::KEY !== $period) {
            return parent::diffInPeriods($period, $skip, $beginning, $end);
        }
        [$beginning, $end] = $beginning->lt($end) ? [$beginning, $end] : [$end, $beginning];
        $calendar          = self::calendar();
        $diff              = 0;
        $cursor            = clone $beginning;
        while ($cursor->lt($end) && $diff < 10000) {
            $cursor = $calendar->add($cursor);
            ++$diff;
        }
        if ($skip > 0) {
            $parameter = $skip + 1;
            $diff      = (int) (ceil($diff / $parameter) * $parameter);
        }
        Log::debug(sprintf('diffInPeriods: CP (skip: %d) between %s and %s is %d.', $skip, $beginning->format('Y-m-d'), $end->format('Y-m-d'), $diff));

        return $diff;
    }

    public function preferredCarbonFormatByPeriod(string $period): string
    {
        if (self::KEY === $period) {
            return 'Y-m-d'; // a custom period is identified by its start date
        }

        return parent::preferredCarbonFormatByPeriod($period);
    }

    public function getViewRange(bool $correct): string
    {
        return parent::getViewRange($correct); // 'CP' passes through both branches unchanged
    }

    public function preferredCarbonFormat(Carbon $start, Carbon $end): string
    {
        return $this->isSingleCustomPeriod($start, $end) ? 'Y-m-d' : parent::preferredCarbonFormat($start, $end);
    }

    public function preferredCarbonLocalizedFormat(Carbon $start, Carbon $end): string
    {
        return $this->isSingleCustomPeriod($start, $end)
            ? (string) trans('config.month_and_day_js', [], Steam::getLocale())
            : parent::preferredCarbonLocalizedFormat($start, $end);
    }

    public function preferredEndOfPeriod(Carbon $start, Carbon $end): string
    {
        return $this->isSingleCustomPeriod($start, $end) ? 'endOfDay' : parent::preferredEndOfPeriod($start, $end);
    }

    public function preferredRangeFormat(Carbon $start, Carbon $end): string
    {
        return $this->isSingleCustomPeriod($start, $end) ? '1D' : parent::preferredRangeFormat($start, $end);
    }

    public function preferredSqlFormat(Carbon $start, Carbon $end): string
    {
        return $this->isSingleCustomPeriod($start, $end) ? '%Y-%m-%d' : parent::preferredSqlFormat($start, $end);
    }

    public function listOfPeriods(Carbon $start, Carbon $end): array
    {
        if (!$this->isSingleCustomPeriod($start, $end)) {
            return parent::listOfPeriods($start, $end);
        }
        $displayFormat = (string) trans('config.month_and_day_js', [], Steam::getLocale());
        $entries       = [];
        for ($begin = clone $start; $begin < $end; $begin->addDay()) {
            $entries[$begin->format('Y-m-d')] = $begin->isoFormat($displayFormat);
        }

        return $entries;
    }

    /**
     * True when the user's view range is CP and [$start, $end] lies within one custom period that is longer
     * than upstream's one-month threshold (so upstream would switch to per-month buckets).
     */
    private function isSingleCustomPeriod(Carbon $start, Carbon $end): bool
    {
        if (self::KEY !== parent::getViewRange(false)) {
            return false;
        }
        if ($start->diffInMonths($end, true) < 1.001) {
            return false;
        }
        [$first, $last] = self::calendar()->periodDays(PeriodCalendar::dayOf($start <= $end ? $start : $end));

        return PeriodCalendar::dayOf($start <= $end ? $end : $start) <= $last && $first <= PeriodCalendar::dayOf($start);
    }
}
