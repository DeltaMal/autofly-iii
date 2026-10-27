<?php

/*
 * CustomPeriodPagesTest.php
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
use FireflyIII\Ext\Budgets\AutoBudgetOptions;
use FireflyIII\Ext\Http\Controllers\PeriodController;
use FireflyIII\Ext\Http\Middleware\SaveExtPeriodPreference;
use FireflyIII\Ext\Periods\ExtNavigation;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Bill;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The acceptance scenario of ext/docs/01: anchor 25, rule "previous", today 2026-10-27.
 *
 * @internal
 */
#[CoversClass(ExtNavigation::class)]
#[CoversClass(SaveExtPeriodPreference::class)]
#[CoversClass(PeriodController::class)]
final class CustomPeriodPagesTest extends FeatureTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', config('app.timezone')));
        $this->withoutVite();
        $this->user = $this->createUser();
        $this->actingAs($this->user);
        $this->createAccount($this->user, 'Cheque');
        Preferences::set('viewRange', 'CP');
        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => []]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testDashboardRangeIsTheSalaryCycle(): void
    {
        $this->get(route('index'))->assertOk();

        self::assertSame('2026-10-23 00:00:00', session('start')->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-24 23:59:59', session('end')->format('Y-m-d H:i:s'));
        self::assertSame('23 Oct – 24 Nov 2026', Navigation::periodShow(session('start'), 'CP'));
    }

    public function testPreviousAndNextPeriods(): void
    {
        $this->get(route('index'))->assertOk();
        $start = session('start');

        // the same steps as upstream's GetConfigurationData::getDateRangeConfig()
        $previous = Navigation::subtractPeriod($start, 'CP');
        $prevStart = Navigation::startOfPeriod($previous, 'CP');
        self::assertSame(['2026-09-25', '2026-10-22'], [$prevStart->format('Y-m-d'), Navigation::endOfPeriod($prevStart, 'CP')->format('Y-m-d')]);
        $next      = Navigation::addPeriod($start, 'CP');
        $nextStart = Navigation::startOfPeriod($next, 'CP');
        self::assertSame(['2026-11-25', '2026-12-24'], [$nextStart->format('Y-m-d'), Navigation::endOfPeriod($nextStart, 'CP')->format('Y-m-d')]);

        $script = $this->get(route('javascript.variables'))->assertOk()->getContent();
        self::assertStringContainsString('25 Sep – 22 Oct 2026', $script);
        self::assertStringContainsString('25 Nov – 24 Dec 2026', $script);
    }

    public function testBudgetPageUsesTheCustomPeriod(): void
    {
        $response = $this->get(route('budgets.index'))->assertOk();
        $response->assertSee('23 Oct – 24 Nov 2026');
        // previous/next period lists on the budget page
        $response->assertSee('25 Sep – 22 Oct 2026');
        $response->assertSee('25 Nov – 24 Dec 2026');
    }

    public function testBudgetLimitsAreCreatedAndReadForTheCustomPeriod(): void
    {
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($this->user);
        $budget     = $repository->store(['name' => 'Groceries', 'active' => true]);
        $currency   = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);

        $this->get(route('budgets.index'))->assertOk()->assertSee('2026-10-23', false)->assertSee('2026-11-24', false);
        $this->post(route('budget-limits.store'), [
            'budget_id' => $budget->id, 'transaction_currency_id' => $currency->id, 'start' => '2026-10-23', 'end' => '2026-11-24', 'amount' => '3000',
        ])->assertStatus(302); // upstream redirects non-AJAX posts back to the budget page

        $limit = BudgetLimit::query()->where('budget_id', $budget->id)->first();
        self::assertNotNull($limit);
        self::assertSame(['2026-10-23', '2026-11-24'], [$limit->start_date->format('Y-m-d'), $limit->end_date->format('Y-m-d')]);
        $this->get(route('budgets.index'))->assertOk()->assertSee(route('budgets.show.limit', [$budget->id, $limit->id]), false);
    }

    public function testReportsTransactionsAndSubscriptionsRender(): void
    {
        $currency = TransactionCurrency::query()->where('code', 'EUR')->first() ?? TransactionCurrency::query()->first();
        Bill::create([
            'name' => 'Rent', 'match' => 'rent', 'amount_min' => '1000', 'amount_max' => '1200', 'user_id' => $this->user->id,
            'user_group_id' => $this->user->user_group_id, 'transaction_currency_id' => $currency->id, 'date' => '2026-01-01',
            'repeat_freq' => 'monthly', 'skip' => 0, 'automatch' => true, 'active' => true,
        ]);
        $this->get(route('subscriptions.index'))->assertOk();
        $this->get(route('transactions.index', ['withdrawal']))->assertOk()->assertSee('23 Oct – 24 Nov 2026');
        $this->get(route('reports.index'))->assertOk();
        self::assertSame('12', ExtNavigation::periodsPerYear());
    }

    public function testPreferencesFormSavesTheSettingsAndKeepsStarts(): void
    {
        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => [['date' => '2026-10-23', 'name' => 'Early']]]);
        $this->get(route('preferences.index'))->assertOk()->assertSee('Custom period (e.g. salary cycle)')->assertSee('value="CP"', false);

        $this->post(route('preferences.update'), [
            'viewRange'  => 'CP',
            'ext_period' => ['anchor_day' => '31', 'business_day_rule' => 'next', 'label' => 'end_month'],
        ])->assertRedirect();

        self::assertSame('CP', Preferences::get('viewRange')->data);
        self::assertSame(
            ['anchor_day' => 31, 'business_day_rule' => 'next', 'label' => 'end_month', 'starts' => [['date' => '2026-10-23', 'name' => 'Early']], 'auto_budgets' => AutoBudgetOptions::defaults()->toArray()],
            Preferences::get('extPeriod')->data
        );

        // manual mode, and invalid input is refused without touching the stored settings
        $this->post(route('preferences.update'), ['viewRange' => 'CP', 'ext_period' => ['anchor_day' => '', 'business_day_rule' => 'none', 'label' => 'range']]);
        self::assertNull(Preferences::get('extPeriod')->data['anchor_day']);
        $this->post(route('preferences.update'), ['viewRange' => 'CP', 'ext_period' => ['anchor_day' => '40', 'business_day_rule' => 'none', 'label' => 'range']]);
        self::assertNull(Preferences::get('extPeriod')->data['anchor_day']);
    }

    public function testClosePeriodMovesTheBoundaryAtOnce(): void
    {
        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => []]);
        $this->get(route('ext.periods.index'))->assertOk()->assertSee('25 Oct – 24 Nov 2026');
        self::assertSame('2026-10-25', session('start')->format('Y-m-d'));

        $this->post(route('ext.periods.close'), ['date' => '2026-10-23', 'name' => 'Oct salary (early)'])->assertRedirect(route('ext.periods.index'));
        self::assertNull(session('start'));

        $this->get(route('ext.periods.index'))->assertOk()->assertSee('Oct salary (early)');
        self::assertSame('2026-10-23', session('start')->format('Y-m-d'));
        self::assertSame('2026-11-24', session('end')->format('Y-m-d'));

        $this->post(route('ext.periods.starts.delete'), ['date' => '2026-10-23'])->assertRedirect();
        self::assertSame([], Preferences::get('extPeriod')->data['starts']);
    }

    public function testManualModeWithoutStartsWarnsAboutCalendarMonths(): void
    {
        $warning = 'Manual mode has no period starts yet, so periods fall back to calendar months.';
        Preferences::set('extPeriod', ['anchor_day' => null, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => []]);
        $this->get(route('ext.periods.index'))->assertOk()->assertSee($warning)->assertSee('1 Oct – 31 Oct 2026');

        Preferences::set('extPeriod', ['anchor_day' => null, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => [['date' => '2026-10-23']]]);
        $this->get(route('ext.periods.index'))->assertOk()->assertDontSee($warning);

        Preferences::set('extPeriod', ['anchor_day' => 25, 'business_day_rule' => 'none', 'label' => 'range', 'starts' => []]);
        $this->get(route('ext.periods.index'))->assertOk()->assertDontSee($warning);
    }

    public function testNonCustomUsersAreUntouched(): void
    {
        Preferences::set('viewRange', '1M');
        $this->get(route('index'))->assertOk();
        self::assertSame('2026-10-01', session('start')->format('Y-m-d'));
        self::assertSame('2026-10-31', session('end')->format('Y-m-d'));
    }
}
