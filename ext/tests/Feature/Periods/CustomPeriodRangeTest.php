<?php

/*
 * CustomPeriodRangeTest.php
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
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * "It should basically work like a custom range, but instead of entering it every time it defaults to the custom
 * cycle." With view range CP (anchor 25, rule "previous", today 2026-10-27, so the cycle is 23 Oct - 24 Nov):
 * every page with a date range starts on the current cycle, prev/next step one whole cycle, and the date picker
 * still takes any custom range for the session without touching the preference.
 *
 * @internal
 */
#[CoversClass(ExtNavigation::class)]
final class CustomPeriodRangeTest extends FeatureTestCase
{
    private const array SETTINGS = ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => []];

    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', config('app.timezone')));
        $this->withoutVite();
        $this->user    = $this->createUser();
        $this->actingAs($this->user);
        $this->account = $this->createAccount($this->user, 'Cheque');
        Preferences::set('viewRange', 'CP');
        Preferences::set('extPeriod', self::SETTINGS);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string, 1: list<mixed>}>
     */
    public static function rangePages(): iterable
    {
        yield 'dashboard' => ['index', []];
        yield 'budgets' => ['budgets.index', []];
        yield 'withdrawals' => ['transactions.index', ['withdrawal']];
        yield 'deposits' => ['transactions.index', ['deposit']];
        yield 'transfers' => ['transactions.index', ['transfers']];
        yield 'subscriptions' => ['subscriptions.index', []];
        yield 'categories' => ['categories.index', []];
        yield 'reports' => ['reports.index', []];
        yield 'account' => ['accounts.show', ['account']];
        yield 'category' => ['categories.show', ['category']];
        yield 'tag' => ['tags.show', ['tag']];
    }

    #[DataProvider('rangePages')]
    public function testEveryRangePageStartsOnTheCurrentCycle(string $route, array $parameters): void
    {
        $this->get(route($route, $this->resolve($parameters)))->assertOk();
        self::assertSame('2026-10-23 00:00:00', session('start')->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-24 23:59:59', session('end')->format('Y-m-d H:i:s'));

        // the date picker (javascript.variables) offers the cycle and whole cycles either side
        $ranges = self::ranges($this->get(route('javascript.variables'))->assertOk()->getContent());
        self::assertSame(['2026-10-23', '2026-11-24'], array_values($ranges)[0]);
        self::assertSame(['2026-09-25', '2026-10-22'], $ranges['25 Sep – 22 Oct 2026']);
        self::assertSame(['2026-11-25', '2026-12-24'], $ranges['25 Nov – 24 Dec 2026']);
    }

    public function testPagesWithAPeriodOverviewListWholeCycles(): void
    {
        foreach ([['categories.show', 'category'], ['tags.show', 'tag'], ['accounts.show', 'account']] as [$route, $what]) {
            $id   = $this->resolve([$what])[0];
            $html = $this->get(route($route, [$id]))->assertOk()->getContent();
            // the account and tag overviews start when they were first used (today here); categories go back to January
            $cycles = 'category' !== $what ? [['2026-10-23', '2026-11-24']] : [['2026-10-23', '2026-11-24'], ['2026-09-25', '2026-10-22'], ['2026-08-25', '2026-09-24']];
            foreach ($cycles as [$start, $end]) {
                self::assertStringContainsString(route($route, [$id, $start, $end]), $html, sprintf('%s should link the cycle %s - %s', $route, $start, $end));
            }
            self::assertStringNotContainsString(route($route, [$id, '2026-10-01', '2026-10-31']), $html);
        }
    }

    public function testTheReportFormDefaultsToTheCycle(): void
    {
        $this->get(route('reports.index'))->assertOk()
            ->assertSee('value="2026-10-23"', false)
            ->assertSee('value="2026-11-24"', false);
    }

    public function testPreviousAndNextStepOneWholeCycle(): void
    {
        // walk two cycles back and two forward through the date picker's own prev/next entries
        $expected = [
            ['2026-08-25', '2026-09-24'], ['2026-09-25', '2026-10-22'], ['2026-10-23', '2026-11-24'], ['2026-11-25', '2026-12-24'], ['2026-12-25', '2027-01-24'],
        ];
        foreach ($expected as $index => [$start, $end]) {
            $this->post(route('daterange'), ['start' => $start, 'end' => $end, 'label' => 'x'])->assertOk();
            $ranges = array_values(self::ranges($this->get(route('javascript.variables'))->assertOk()->getContent()));
            self::assertSame([$start, $end], $ranges[0]);
            if ($index > 0) {
                self::assertContains($expected[$index - 1], $ranges, 'previous cycle');
            }
            if ($index < count($expected) - 1) {
                self::assertContains($expected[$index + 1], $ranges, 'next cycle');
            }
        }
    }

    public function testACustomRangeHoldsForTheSessionWithoutChangingThePreference(): void
    {
        $this->get(route('index'))->assertOk();
        $this->post(route('daterange'), ['start' => '2026-10-01', 'end' => '2026-10-15', 'label' => (string) trans('firefly.customRange')])->assertOk();

        foreach (['index', 'budgets.index'] as $route) {
            $this->get(route($route))->assertOk();
            self::assertSame('2026-10-01', session('start')->format('Y-m-d'));
            self::assertSame('2026-10-15', session('end')->format('Y-m-d'));
        }
        $this->get(route('transactions.index', ['withdrawal']))->assertOk();
        self::assertSame('2026-10-01', session('start')->format('Y-m-d'));

        // the picker still offers the cycle the custom range starts in
        $ranges = self::ranges($this->get(route('javascript.variables'))->assertOk()->getContent());
        self::assertSame(['2026-10-01', '2026-10-15'], array_values($ranges)[0]);
        self::assertSame(['2026-09-25', '2026-10-22'], $ranges['25 Sep – 22 Oct 2026']);

        // the preference is untouched, and a new session starts on the cycle again
        self::assertSame('CP', Preferences::get('viewRange')->data);
        self::assertSame(self::SETTINGS, Preferences::get('extPeriod')->data);
        $this->flushSession();
        $this->get(route('index'))->assertOk();
        self::assertSame('2026-10-23', session('start')->format('Y-m-d'));
        self::assertSame('2026-11-24', session('end')->format('Y-m-d'));
    }

    /**
     * The date picker's ranges from javascript.variables: label => [start, end].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function ranges(string $script): array
    {
        preg_match_all('/ranges\["([^"]+)"\]\s*=\s*\[moment\("(\d{4}-\d{2}-\d{2})"\),\s*moment\("(\d{4}-\d{2}-\d{2})"\)\]/u', $script, $matches, PREG_SET_ORDER);
        $ranges = [];
        foreach ($matches as $match) {
            $ranges[$match[1]] = [$match[2], $match[3]];
        }

        return $ranges;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private function resolve(array $parameters): array
    {
        return array_map(function (mixed $parameter): mixed {
            return match ($parameter) {
                'account'  => $this->account->id,
                'category' => Category::query()->firstOrCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'Food'])->id,
                'tag'      => Tag::query()->firstOrCreate(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => 'holiday', 'tag_mode' => 'nothing'])->id,
                default    => $parameter,
            };
        }, $parameters);
    }
}
