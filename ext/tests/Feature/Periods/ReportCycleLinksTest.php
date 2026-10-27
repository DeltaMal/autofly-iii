<?php

/*
 * ReportCycleLinksTest.php
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
use FireflyIII\Ext\Periods\ReportCycleLinks;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Account;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Cycle quick links on the reports page (anchor 25, rule "previous", today 2026-10-27, first transaction in
 * August 2025).
 *
 * @internal
 */
#[CoversClass(ReportCycleLinks::class)]
final class ReportCycleLinksTest extends FeatureTestCase
{
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
        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testCustomPeriodUsersGetCycleLinksGroupedByYear(): void
    {
        Preferences::set('viewRange', 'CP');
        $html = $this->withSession(['first' => Carbon::parse('2025-08-10', config('app.timezone'))])->get(route('reports.index'))->assertOk()->getContent();

        self::assertStringContainsString('id="ext-cycle-links"', $html);
        self::assertStringContainsString('Custom periods (opens the default report)', $html);
        // the first cycle contains the first transaction, the last one is the current cycle
        $first   = route('reports.report.default', [(string) $this->account->id, '20250725', '20250824']);
        $current = route('reports.report.default', [(string) $this->account->id, '20261023', '20261124']);
        self::assertStringContainsString('href="'.$first.'"', $html);
        self::assertStringContainsString('href="'.$current.'">23 Oct – 24 Nov 2026</a>', $html);
        self::assertStringNotContainsString('20261125', $html);
        // grouped by the year the cycle starts in; 2025 comes before 2026, like upstream's months
        self::assertLessThan(strpos($html, '20260123'), strpos($html, '20251225'));
        self::assertStringContainsString('href="'.route('reports.report.default', [(string) $this->account->id, '20251225', '20260122']).'">25 Dec 2025 – 22 Jan 2026</a>', $html);

        $groups  = ReportCycleLinks::grouped(Carbon::parse('2025-08-10', config('app.timezone')));
        self::assertSame([2025, 2026], array_keys($groups));
        self::assertCount(6, $groups[2025]); // 25 Jul, 25 Aug, 25 Sep, 24 Oct, 25 Nov, 25 Dec
        self::assertSame('20261023', end($groups[2026])['start']);

        // the link opens upstream's default report for exactly that cycle
        $this->get($current)->assertOk();
    }

    public function testOtherUsersSeeTheReportsPageUnchanged(): void
    {
        Preferences::set('viewRange', '1M');
        $html = $this->withSession(['first' => Carbon::parse('2025-08-10', config('app.timezone'))])->get(route('reports.index'))->assertOk()->getContent();

        self::assertStringNotContainsString('ext-cycle-links', $html);
        self::assertStringNotContainsString('Custom periods', $html);
        self::assertSame([], ReportCycleLinks::grouped(Carbon::parse('2025-08-10', config('app.timezone'))));
    }
}
