<?php

/*
 * ExtNavigationParityTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Periods;

use Carbon\Carbon;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Navigation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * For every upstream key, ExtNavigation must return exactly what upstream Navigation returns
 * (results and exceptions), even while the user has custom period settings stored.
 *
 * @internal
 */
#[CoversClass(ExtNavigation::class)]
final class ExtNavigationParityTest extends FeatureTestCase
{
    private const array KEYS = [
        '1D', 'daily', '1W', 'week', 'weekly', '1M', 'month', 'monthly', '3M', 'quarter', 'quarterly', '6M', 'half-year', 'half_year',
        'year', 'yearly', '1Y', 'custom', 'last7', 'last30', 'last90', 'last365', 'MTD', 'QTD', 'YTD', 'unknown-key',
    ];
    private const array DATES = ['2026-10-27 13:14:15', '2026-01-31 00:00:00', '2024-02-29 23:59:59', '2027-12-25 08:00:00', '2030-06-15 12:00:00'];

    public static function keys(): iterable
    {
        foreach (self::KEYS as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('keys')]
    public function testEveryRangeMethodMatchesUpstream(string $key): void
    {
        $this->actingAs($this->createUser());
        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => [['date' => '2026-10-23']]]);
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', config('app.timezone')));
        session(['start' => Carbon::parse('2026-10-01'), 'end' => Carbon::parse('2026-10-31')]);

        $upstream = new Navigation();
        $ext      = new ExtNavigation();
        foreach (self::DATES as $text) {
            $date  = static fn (): Carbon => Carbon::parse($text, config('app.timezone'));
            $other = static fn (): Carbon => Carbon::parse($text, config('app.timezone'))->addMonths(14)->addDays(3);
            $calls = [
                'addPeriod'                     => static fn (Navigation $n) => $n->addPeriod($date(), $key),
                'addPeriod skip 2'              => static fn (Navigation $n) => $n->addPeriod($date(), $key, 2),
                'subtractPeriod'                => static fn (Navigation $n) => $n->subtractPeriod($date(), $key),
                'subtractPeriod 3'              => static fn (Navigation $n) => $n->subtractPeriod($date(), $key, 3),
                'startOfPeriod'                 => static fn (Navigation $n) => $n->startOfPeriod($date(), $key),
                'endOfPeriod'                   => static fn (Navigation $n) => $n->endOfPeriod($date(), $key),
                'endOfX'                        => static fn (Navigation $n) => $n->endOfX($date(), $key, null),
                'endOfX max'                    => static fn (Navigation $n) => $n->endOfX($date(), $key, $date()->addDays(2)),
                'updateStartDate'               => static fn (Navigation $n) => $n->updateStartDate($key, $date()),
                'updateEndDate'                 => static fn (Navigation $n) => $n->updateEndDate($key, $date()),
                'periodShow'                    => static fn (Navigation $n) => $n->periodShow($date(), $key),
                'blockPeriods'                  => static fn (Navigation $n) => $n->blockPeriods($date(), $other(), $key),
                'diffInPeriods'                 => static fn (Navigation $n) => $n->diffInPeriods($key, 0, $date(), $other()),
                'diffInPeriods skip'            => static fn (Navigation $n) => $n->diffInPeriods($key, 2, $date(), $other()),
                'preferredCarbonFormatByPeriod' => static fn (Navigation $n) => $n->preferredCarbonFormatByPeriod($key),
            ];
            foreach ($calls as $name => $call) {
                self::assertSame(self::outcome($call, $upstream), self::outcome($call, $ext), sprintf('%s("%s") on %s', $name, $key, $text));
            }
        }
        Carbon::setTestNow();
    }

    public function testDistanceHelpersAndViewRangeMatchUpstreamForNonCustomRanges(): void
    {
        $this->actingAs($this->createUser());
        $upstream = new Navigation();
        $ext      = new ExtNavigation();
        foreach (['1M', 'last30', '1Y'] as $range) {
            Preferences::set('viewRange', $range);
            self::assertSame($upstream->getViewRange(true), $ext->getViewRange(true));
            self::assertSame($upstream->getViewRange(false), $ext->getViewRange(false));
            foreach ([['2026-10-23', '2026-11-24'], ['2026-10-01', '2026-10-31'], ['2026-01-01', '2026-12-31'], ['2024-01-01', '2026-12-31']] as [$a, $b]) {
                $start = Carbon::parse($a);
                $end   = Carbon::parse($b)->endOfDay();
                self::assertSame($upstream->preferredCarbonFormat($start, $end), $ext->preferredCarbonFormat($start, $end));
                self::assertSame($upstream->preferredCarbonLocalizedFormat($start, $end), $ext->preferredCarbonLocalizedFormat($start, $end));
                self::assertSame($upstream->preferredEndOfPeriod($start, $end), $ext->preferredEndOfPeriod($start, $end));
                self::assertSame($upstream->preferredRangeFormat($start, $end), $ext->preferredRangeFormat($start, $end));
                self::assertSame($upstream->preferredSqlFormat($start, $end), $ext->preferredSqlFormat($start, $end));
                self::assertSame($upstream->listOfPeriods($start, $end), $ext->listOfPeriods($start, $end));
            }
        }
    }

    public function testNavigationIsBoundToExtNavigation(): void
    {
        self::assertInstanceOf(ExtNavigation::class, app('navigation'));
        self::assertContains('CP', config('firefly.valid_view_ranges'));
        self::assertSame('CP', config('firefly.range_to_repeat_freq.CP'));
    }

    private static function outcome(callable $call, Navigation $navigation): array
    {
        try {
            return ['ok', self::normalise($call($navigation))];
        } catch (Throwable $e) {
            return ['exception', $e::class, $e->getMessage()];
        }
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s.u e');
        }
        if (is_array($value)) {
            return array_map(self::normalise(...), $value);
        }

        return $value;
    }
}
