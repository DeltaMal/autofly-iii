<?php

/*
 * PeriodCalendarTest.php
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

namespace FireflyIII\Ext\Tests\Unit\Periods;

use Carbon\Carbon;
use FireflyIII\Ext\Budgets\AutoBudgetOptions;
use FireflyIII\Ext\Periods\PeriodCalendar;
use FireflyIII\Ext\Periods\PeriodSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces ext/tests/fixtures/period-vectors.json exactly.
 *
 * @internal
 */
#[CoversClass(PeriodCalendar::class)]
#[CoversClass(PeriodSettings::class)]
final class PeriodCalendarTest extends TestCase
{
    private const string TZ = 'Africa/Johannesburg';

    public static function periodVectors(): iterable
    {
        foreach (self::vectors()['periods'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    public static function moveVectors(): iterable
    {
        foreach (self::vectors()['moves'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    #[DataProvider('periodVectors')]
    public function testPeriod(array $vector): void
    {
        $calendar       = PeriodCalendar::fromArray($vector['settings']);
        [$start, $end]  = $calendar->periodFor(Carbon::parse($vector['date'].' 12:34:56', self::TZ));

        self::assertSame($vector['start'].' 00:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame($vector['end'].' 23:59:59', $end->format('Y-m-d H:i:s'));
        self::assertSame(self::TZ, $start->getTimezone()->getName());
        self::assertSame($vector['label'], $calendar->label($start, $end, 'en'));

        $previous       = $calendar->periodFor($start->copy()->subDay());
        self::assertSame($vector['previous'], [$previous[0]->format('Y-m-d'), $previous[1]->format('Y-m-d')]);
        $next           = $calendar->periodFor($end->copy()->addDay());
        self::assertSame($vector['next'], [$next[0]->format('Y-m-d'), $next[1]->format('Y-m-d')]);
    }

    #[DataProvider('moveVectors')]
    public function testMove(array $vector): void
    {
        $calendar = PeriodCalendar::fromArray($vector['settings']);
        $date     = Carbon::parse($vector['date'].' 08:15:00', self::TZ);
        $moved    = 'add' === $vector['direction'] ? $calendar->add($date, $vector['skip']) : $calendar->subtract($date, $vector['skip']);

        self::assertSame($vector['expected'].' 08:15:00', $moved->format('Y-m-d H:i:s'));
    }

    public function testDayNumbersRoundTrip(): void
    {
        for ($day = -800000; $day <= 800000; $day += 997) {
            [$y, $m, $d] = PeriodCalendar::civil($day);
            self::assertSame($day, PeriodCalendar::days($y, $m, $d));
        }
        self::assertSame(0, PeriodCalendar::parseDay('1970-01-01'));
    }

    public function testPeriodsAreContiguousOverTenYears(): void
    {
        $calendar = PeriodCalendar::fromArray(['anchor_day' => 31, 'business_day_rule' => 'previous', 'starts' => [['date' => '2026-10-23']]]);
        $cursor   = PeriodCalendar::parseDay('2022-01-01');
        while ($cursor < PeriodCalendar::parseDay('2032-01-01')) {
            [$start, $end] = $calendar->periodDays($cursor);
            self::assertTrue($start <= $cursor && $cursor <= $end);
            $cursor        = $end + 1;
            self::assertSame($cursor, $calendar->periodDays($cursor)[0]);
        }
    }

    public function testResultDoesNotDependOnQueryOrder(): void
    {
        $settings = ['anchor_day' => 25, 'business_day_rule' => 'previous', 'starts' => [['date' => '2021-03-20'], ['date' => '2031-06-21']]];
        $fresh    = PeriodCalendar::fromArray($settings);
        $warm     = PeriodCalendar::fromArray($settings);
        $warm->periodFor(Carbon::parse('2040-01-01', self::TZ));
        foreach (['2021-03-21', '2026-10-27', '2031-06-22', '2018-01-01'] as $date) {
            self::assertEquals($fresh->periodFor(Carbon::parse($date, self::TZ)), $warm->periodFor(Carbon::parse($date, self::TZ)));
        }
    }

    public function testSettingsAreLenient(): void
    {
        self::assertSame(PeriodSettings::defaults()->toArray(), PeriodSettings::fromArray('garbage')->toArray());
        $settings = PeriodSettings::fromArray(['anchor_day' => 99, 'business_day_rule' => 'x', 'label' => 'y', 'starts' => [['date' => '2026-02-30'], ['date' => '2026-10-23', 'name' => ' Early ']]]);
        self::assertSame(['anchor_day' => 25, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => [['date' => '2026-10-23', 'name' => 'Early']], 'auto_budgets' => AutoBudgetOptions::defaults()->toArray()], $settings->toArray());
        self::assertNull(PeriodSettings::fromArray(['anchor_day' => null])->anchorDay);
    }

    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/period-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}
