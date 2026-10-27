<?php

/*
 * PeriodCalendar.php
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
use RuntimeException;

/**
 * Custom period maths (ext/docs/01-custom-periods.md). A pure value class: no container, no database.
 *
 * The reference implementation is ext/scripts/period_vectors.py; this class (and the line-for-line JS port
 * in ext/resources/assets/period-calendar.js) must reproduce ext/tests/fixtures/period-vectors.json.
 *
 * Internally every date is a day number (days since 1970-01-01), so time zones and DST never matter
 * until a Carbon is built for the caller, in the caller's time zone.
 */
final class PeriodCalendar
{
    private const int MARGIN_YEARS = 3;

    /** @var list<int> sorted, unique */
    private array $explicit = [];

    /** @var array<int, string> day number => name of an explicit start */
    private array $names = [];

    /** @var list<int> sorted, unique period starts for the years [$lo, $hi] */
    private array $boundaries = [];
    private ?int $lo = null;
    private ?int $hi = null;

    public function __construct(private readonly PeriodSettings $settings)
    {
        foreach ($settings->starts as $item) {
            $day              = self::parseDay($item['date']);
            $this->explicit[] = $day;
            if (null !== ($item['name'] ?? null) && '' !== $item['name'] && !array_key_exists($day, $this->names)) {
                $this->names[$day] = $item['name'];
            }
        }
        $this->explicit = array_values(array_unique($this->explicit));
        sort($this->explicit);
    }

    public static function fromArray(array $settings): self
    {
        return new self(PeriodSettings::fromArray($settings));
    }

    public function settings(): PeriodSettings
    {
        return $this->settings;
    }

    /**
     * The period containing $date: [start 00:00:00, end 23:59:59], in $date's time zone.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFor(Carbon $date): array
    {
        [$start, $end] = $this->periodDays(self::dayOf($date));

        return [self::toCarbon($start, $date)->startOfDay(), self::toCarbon($end, $date)->endOfDay()->milli(0)];
    }

    public function startOf(Carbon $date): Carbon
    {
        return $this->periodFor($date)[0];
    }

    public function endOf(Carbon $date): Carbon
    {
        return $this->periodFor($date)[1];
    }

    /**
     * Move skip + 1 whole periods forward, keeping the day offset from the period start, clamped to the
     * target period's end. The time of day of $date is kept.
     */
    public function add(Carbon $date, int $skip = 0): Carbon
    {
        return self::toCarbon($this->move(self::dayOf($date), $skip, 1), $date);
    }

    public function subtract(Carbon $date, int $skip = 0): Carbon
    {
        return self::toCarbon($this->move(self::dayOf($date), $skip, -1), $date);
    }

    /**
     * The label of the period [$start, $end]. $formats are isoFormat strings (see ext::periods), so month
     * names follow $locale.
     *
     * @param array{day_month?: string, day_month_year?: string, month_year?: string, range?: string} $formats
     */
    public function label(Carbon $start, Carbon $end, ?string $locale = null, array $formats = []): string
    {
        $formats  += ['day_month' => 'D MMM', 'day_month_year' => 'D MMM YYYY', 'month_year' => 'MMMM YYYY', 'range' => ':start – :end'];
        $startDay = self::dayOf($start);
        if (array_key_exists($startDay, $this->names)) {
            return $this->names[$startDay];
        }
        $start    = $start->copy()->locale($locale ?? 'en');
        $end      = $end->copy()->locale($locale ?? 'en');

        return match ($this->settings->label) {
            'start_month' => $start->isoFormat($formats['month_year']),
            'end_month'   => $end->isoFormat($formats['month_year']),
            default       => str_replace(
                [':start', ':end'],
                [$start->isoFormat($start->year === $end->year ? $formats['day_month'] : $formats['day_month_year']), $end->isoFormat($formats['day_month_year'])],
                $formats['range']
            ),
        };
    }

    /**
     * All period starts (Y-m-d) in the years around $date that are currently computed.
     *
     * @return list<string>
     */
    public function boundaries(Carbon $date): array
    {
        $this->cover(self::dayOf($date));

        return array_map(self::formatDay(...), $this->boundaries);
    }

    /**
     * @return array{0: int, 1: int} first and last day of the period containing $day
     */
    public function periodDays(int $day): array
    {
        $this->cover($day);
        $index = $this->indexAtOrBefore($day);

        return [$this->boundaries[$index], $this->boundaries[$index + 1] - 1];
    }

    private function move(int $day, int $skip, int $direction): int
    {
        [$start] = $this->periodDays($day);
        $offset  = $day - $start;
        $target  = $start;
        for ($i = 0; $i < $skip + 1; ++$i) {
            $target = $direction > 0 ? $this->periodDays($target)[1] + 1 : $this->periodDays($target - 1)[0];
        }

        return min($target + $offset, $this->periodDays($target)[1]);
    }

    /**
     * Makes sure there is a boundary <= $day and one > $day, extending the computed years lazily.
     */
    private function cover(int $day): void
    {
        $year = self::civil($day)[0];
        if (null === $this->lo || $year - 1 < $this->lo || $year + 1 > $this->hi) {
            $this->compute(min($this->lo ?? PHP_INT_MAX, $year - self::MARGIN_YEARS), max($this->hi ?? PHP_INT_MIN, $year + self::MARGIN_YEARS));
        }
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $count = count($this->boundaries);
            if ($count > 0 && $this->boundaries[0] <= $day && $this->boundaries[$count - 1] > $day) {
                return;
            }
            $this->compute($this->lo - self::MARGIN_YEARS, $this->hi + self::MARGIN_YEARS);
        }

        throw new RuntimeException(sprintf('No custom period contains %s.', self::formatDay($day)));
    }

    private function compute(int $lo, int $hi): void
    {
        $this->lo         = $lo;
        $this->hi         = $hi;
        $this->boundaries = null !== $this->settings->anchorDay || [] === $this->explicit
            ? $this->anchored($lo, $hi)
            : $this->manual($lo, $hi);
    }

    /**
     * @return list<int>
     */
    private function anchored(int $lo, int $hi): array
    {
        $anchor = $this->settings->anchorDay ?? 1;
        $rule   = null === $this->settings->anchorDay ? 'none' : $this->settings->businessDayRule;
        // Computed starts must cover every explicit start's neighbours, so "nearest" never depends on the window.
        if ([] !== $this->explicit) {
            $lo = min($lo, self::civil($this->explicit[0])[0] - 1);
            $hi = max($hi, self::civil($this->explicit[count($this->explicit) - 1])[0] + 1);
        }
        $computed = [];
        for ($year = $lo; $year <= $hi; ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                $computed[] = self::shiftWeekend(self::clampDay($year, $month, $anchor), $rule);
            }
        }
        $replaced = [];
        foreach ($this->explicit as $start) {
            // nearest computed start; a tie goes to the later one
            $nearest = null;
            foreach ($computed as $candidate) {
                if (null === $nearest
                    || abs($candidate - $start) < abs($nearest - $start)
                    || (abs($candidate - $start) === abs($nearest - $start) && $candidate > $nearest)) {
                    $nearest = $candidate;
                }
            }
            $replaced[$nearest] = true;
        }
        $result   = $this->explicit;
        foreach ($computed as $candidate) {
            if (!array_key_exists($candidate, $replaced)) {
                $result[] = $candidate;
            }
        }
        $result   = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    /**
     * Calendar months before the first explicit start, the explicit starts, then a projection after the last.
     *
     * @return list<int>
     */
    private function manual(int $lo, int $hi): array
    {
        $first  = $this->explicit[0];
        $last   = $this->explicit[count($this->explicit) - 1];
        $result = [];
        for ($year = $lo; $year <= $hi; ++$year) {
            for ($month = 1; $month <= 12; ++$month) {
                $day = self::days($year, $month, 1);
                if ($day < $first) {
                    $result[] = $day;
                }
            }
        }
        array_push($result, ...$this->explicit);
        if (count($this->explicit) >= 2) {
            $length = $last - $this->explicit[count($this->explicit) - 2];
            for ($step = $last + $length; self::civil($step)[0] <= $hi; $step += $length) {
                $result[] = $step;
            }
        } else {
            [$year, $month, $dayOfMonth] = self::civil($last);
            for ($k = 1; ; ++$k) {
                $index = $year * 12 + ($month - 1) + $k;
                $y     = intdiv($index, 12);
                if ($y > $hi) {
                    break;
                }
                $result[] = self::clampDay($y, $index % 12 + 1, $dayOfMonth);
            }
        }
        $result = array_values(array_unique($result));
        sort($result);

        return $result;
    }

    private function indexAtOrBefore(int $day): int
    {
        $low  = 0;
        $high = count($this->boundaries) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($this->boundaries[$mid] <= $day) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    private static function shiftWeekend(int $day, string $rule): int
    {
        $step = ['previous' => -1, 'next' => 1][$rule] ?? 0;
        while (0 !== $step && self::weekday($day) >= 5) {
            $day += $step;
        }

        return $day;
    }

    private static function clampDay(int $year, int $month, int $day): int
    {
        return self::days($year, $month, min($day, self::daysInMonth($year, $month)));
    }

    /** Days in a month, without ext-calendar (not installed in fireflyiii/base). */
    private static function daysInMonth(int $year, int $month): int
    {
        $leap = (0 === $year % 4 && 0 !== $year % 100) || 0 === $year % 400;

        return [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$month - 1];
    }

    /** Monday = 0 … Sunday = 6 (1970-01-01 was a Thursday). */
    private static function weekday(int $day): int
    {
        return (($day % 7) + 7 + 3) % 7;
    }

    /** Days since 1970-01-01 (proleptic Gregorian; H. Hinnant's days_from_civil). */
    public static function days(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era  = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe  = $year - $era * 400;
        $doy  = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe  = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * @return array{0: int, 1: int, 2: int} year, month, day
     */
    public static function civil(int $days): array
    {
        $days += 719468;
        $era  = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $doe  = $days - $era * 146097;
        $yoe  = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy  = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp   = intdiv(5 * $doy + 2, 153);
        $day  = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp + ($mp < 10 ? 3 : -9);

        return [$yoe + $era * 400 + ($month <= 2 ? 1 : 0), $month, $day];
    }

    public static function dayOf(Carbon $date): int
    {
        return self::days($date->year, $date->month, $date->day);
    }

    public static function parseDay(string $date): int
    {
        return self::days((int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2));
    }

    public static function formatDay(int $day): string
    {
        [$year, $month, $dayOfMonth] = self::civil($day);

        return sprintf('%04d-%02d-%02d', $year, $month, $dayOfMonth);
    }

    /** A copy of $template (time of day and time zone kept) on day number $day. */
    private static function toCarbon(int $day, Carbon $template): Carbon
    {
        [$year, $month, $dayOfMonth] = self::civil($day);

        return $template->copy()->setDate($year, $month, $dayOfMonth);
    }
}

