<?php

/*
 * CustomPeriodAutoBudgetsTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Budgets;

use Carbon\Carbon;
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Ext\Budgets\CustomPeriodAutoBudgets;
use FireflyIII\Ext\Budgets\ExtCreateAutoBudgetLimits;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Jobs\CreateAutoBudgetLimits;
use FireflyIII\Models\Account;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Cronjobs\AutoBudgetCronjob;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Monthly auto-budgets on custom periods (anchor 25, rule "previous"):
 * 25 Sep - 22 Oct 2026, 23 Oct - 24 Nov 2026 (25 Oct is a Sunday), 25 Nov - 24 Dec 2026.
 *
 * @internal
 */
#[CoversClass(ExtCreateAutoBudgetLimits::class)]
#[CoversClass(CustomPeriodAutoBudgets::class)]
final class CustomPeriodAutoBudgetsTest extends FeatureTestCase
{
    private const array CP = ['anchor_day' => 25, 'business_day_rule' => 'previous', 'label' => 'range', 'starts' => []];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testTheProviderBindsTheExtJob(): void
    {
        self::assertInstanceOf(ExtCreateAutoBudgetLimits::class, app(CreateAutoBudgetLimits::class, [Carbon::parse('2026-10-01')]));
    }

    public function testResetRolloverAndAdjustedFollowThreeCustomPeriods(): void
    {
        $user    = $this->cpUser('cp@example.com');
        $account = $this->createAccount($user, 'Cheque');
        $reset    = $this->autoBudget($user, 'Reset', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $rollover = $this->autoBudget($user, 'Rollover', AutoBudgetType::AUTO_BUDGET_ROLLOVER, '100');
        $adjusted = $this->autoBudget($user, 'Adjusted', AutoBudgetType::AUTO_BUDGET_ADJUSTED, '100');

        // cycle 1: no previous limits, so every type gets the plain amount
        $this->runJob($user, '2026-09-25');
        self::assertSame([['2026-09-25', '2026-10-22', '100']], $this->limits($reset));
        self::assertSame([['2026-09-25', '2026-10-22', '100']], $this->limits($rollover));
        self::assertSame([['2026-09-25', '2026-10-22', '100']], $this->limits($adjusted));

        $this->spend($user, $account, $reset, '2026-10-01', '30');
        $this->spend($user, $account, $rollover, '2026-10-01', '30');
        $this->spend($user, $account, $adjusted, '2026-10-01', '130');

        // a calendar 1st inside the period: nothing new, and never a calendar-month limit
        $this->runJob($user, '2026-10-01');
        self::assertCount(1, $this->limits($reset));
        self::assertCount(1, $this->limits($rollover));
        self::assertCount(1, $this->limits($adjusted));

        // cycle 2 starts on Friday 23 Oct (25 Oct is a Sunday)
        $this->runJob($user, '2026-10-23');
        self::assertSame(['2026-10-23', '2026-11-24', '100'], $this->limits($reset)[1]);
        self::assertSame(['2026-10-23', '2026-11-24', '170'], $this->limits($rollover)[1]); // 100 + 70 left
        self::assertSame(['2026-10-23', '2026-11-24', '70'], $this->limits($adjusted)[1]);  // 100 + 100 - 130

        $this->spend($user, $account, $rollover, '2026-11-02', '200'); // overspent by 30

        // cycle 3
        $this->runJob($user, '2026-11-25');
        self::assertSame(['2026-11-25', '2026-12-24', '100'], $this->limits($reset)[2]);
        self::assertSame(['2026-11-25', '2026-12-24', '100'], $this->limits($rollover)[2]); // negative left: reset
        self::assertSame(['2026-11-25', '2026-12-24', '170'], $this->limits($adjusted)[2]); // 70 + 100 - 0

        // idempotent: running again (same day, later in the period, a calendar 1st) creates nothing
        $before = BudgetLimit::query()->count();
        $this->runJob($user, '2026-11-25');
        $this->runJob($user, '2026-12-01');
        $this->runJob($user, '2026-12-24');
        self::assertSame($before, BudgetLimit::query()->count());
        self::assertSame(0, BudgetLimit::query()->where('start_date', 'like', '%-01')->count());
    }

    public function testOverspendingThatEatsTheWholeAdjustedAmountLeavesOne(): void
    {
        $user     = $this->cpUser('cp@example.com');
        $account  = $this->createAccount($user, 'Cheque');
        $adjusted = $this->autoBudget($user, 'Adjusted', AutoBudgetType::AUTO_BUDGET_ADJUSTED, '100');
        $this->runJob($user, '2026-09-25');
        $this->spend($user, $account, $adjusted, '2026-10-05', '250');
        $this->runJob($user, '2026-10-23');
        self::assertSame(['2026-10-23', '2026-11-24', '1'], $this->limits($adjusted)[1]);
    }

    public function testNonMonthlyAutoBudgetsOfACustomPeriodUserStayUpstream(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $weekly = $this->autoBudget($user, 'Weekly', AutoBudgetType::AUTO_BUDGET_RESET, '50', 'weekly');
        $this->runJob($user, '2026-10-26'); // a Monday
        self::assertSame([['2026-10-26', '2026-11-01', '50']], $this->limits($weekly));
        $this->runJob($user, '2026-10-27'); // not a Monday: upstream does nothing
        self::assertCount(1, $this->limits($weekly));
    }

    public function testTheUpstreamCronUsesCustomPeriodsAndMakesNoCalendarLimits(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $budget = $this->autoBudget($user, 'Reset', AutoBudgetType::AUTO_BUDGET_RESET, '100');

        $cron = new AutoBudgetCronjob();
        $cron->setDate(Carbon::parse('2026-11-01', config('app.timezone')));
        $cron->setUsers(collect([$user]));
        $cron->fire();

        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($budget));
    }

    /**
     * Switching to CP mid-month: upstream's calendar limits for the auto-budget are cut back or replaced, so the
     * current custom period has exactly one limit and no limits overlap.
     */
    public function testSwitchingToCustomPeriodsMidMonth(): void
    {
        $user = $this->createUser('switch@example.com');
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', '1M');

        // September on 1M: upstream's cron makes the September limit
        $budget = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->runJob($user, '2026-09-01');
        // 1 October: upstream's cron makes the October limit
        $this->runJob($user, '2026-10-01');
        self::assertSame([['2026-09-01', '2026-09-30', '100'], ['2026-10-01', '2026-10-31', '100']], $this->limits($budget));

        // a limit the user set by hand on another budget is never touched
        $manual = $this->autoBudget($user, 'Manual', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->limit($manual, '2026-10-01', '2026-10-31', '555');

        // 15 October: the user switches to CP and the cron runs
        Preferences::setForUser($user, 'viewRange', 'CP');
        Preferences::setForUser($user, 'extPeriod', self::CP);
        $this->runJob($user, '2026-10-15');

        // generated limits: September is cut back, October (starts inside the cycle) is removed
        self::assertSame([['2026-09-01', '2026-09-24', '100'], ['2026-09-25', '2026-10-22', '100']], $this->limits($budget));
        self::assertSame([['2026-10-01', '2026-10-31', '555']], $this->limits($manual)); // overlaps, so nothing is created

        $this->runJob($user, '2026-10-23');
        self::assertSame(['2026-10-23', '2026-11-24', '100'], $this->limits($budget)[2]);
        self::assertSame([['2026-10-01', '2026-10-31', '555']], $this->limits($manual));
        $this->assertNoOverlaps($budget);
    }

    /**
     * Only generated limits are taken over: a limit that is not generated stays, even when it is a calendar month
     * with exactly the auto-budget amount (e.g. upstream's first limit of a budget created on 1M).
     */
    public function testALimitThatIsNotGeneratedIsNeverTouchedEvenWithTheAutoBudgetAmount(): void
    {
        $user = $this->createUser('switch@example.com');
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', '1M');

        $byHand = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->limit($byHand, '2026-10-01', '2026-10-31', '100');

        Carbon::setTestNow(Carbon::parse('2026-10-10 09:00:00', config('app.timezone')));
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $created    = $repository->store([
            'name' => 'Fuel', 'active' => true, 'auto_budget_type' => 'rollover', 'auto_budget_amount' => '80', 'auto_budget_period' => 'monthly',
            'currency_id' => Amount::getPrimaryCurrencyByUserGroup($user->userGroup)->id,
        ]);
        self::assertSame([['2026-10-01', '2026-10-31', '80']], $this->limits($created)); // upstream's first limit, on 1M

        Preferences::setForUser($user, 'viewRange', 'CP');
        Preferences::setForUser($user, 'extPeriod', self::CP);
        $this->runJob($user, '2026-10-15');
        $this->runJob($user, '2026-10-23');

        self::assertSame([['2026-10-01', '2026-10-31', '100']], $this->limits($byHand));
        self::assertSame([['2026-10-01', '2026-10-31', '80']], $this->limits($created));
    }

    /**
     * A budget created with a monthly auto-budget while on CP: upstream's first calendar limit becomes the current
     * cycle's limit at once, through the web form and through the API.
     */
    public function testANewBudgetOnCustomPeriodsGetsTheCycleLimitAtOnce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $user     = $this->cpUser('cp@example.com');
        $currency = Amount::getPrimaryCurrencyByUserGroup($user->userGroup);

        $this->post(route('budgets.store'), [
            'name' => 'Web budget', 'active' => '1', 'auto_budget_type' => '2', 'auto_budget_currency_id' => (string) $currency->id,
            'auto_budget_amount' => '150', 'auto_budget_period' => 'monthly',
        ])->assertRedirect();
        $web = Budget::query()->where('name', 'Web budget')->firstOrFail();
        self::assertSame([['2026-10-23', '2026-11-24', '150']], $this->limits($web));
        self::assertTrue((bool) $web->budgetlimits()->first()->generated);

        $this->actingAsApiUser($user);
        $this->postJson(route('api.v1.budgets.store'), [
            'name' => 'API budget', 'auto_budget_type' => 'adjusted', 'auto_budget_currency_code' => $currency->code,
            'auto_budget_amount' => '75', 'auto_budget_period' => 'monthly',
        ])->assertSuccessful();
        $api = Budget::query()->where('name', 'API budget')->firstOrFail();
        self::assertSame([['2026-10-23', '2026-11-24', '75']], $this->limits($api));

        // the cron sees the cycle limit and adds nothing; the next cycle follows the cycle rules
        $this->runJob($user, '2026-10-27');
        self::assertCount(1, $this->limits($web));
        $this->runJob($user, '2026-11-25');
        self::assertSame(['2026-11-25', '2026-12-24', '300'], $this->limits($web)[1]); // rollover: 150 + 150 unspent
        self::assertSame(['2026-11-25', '2026-12-24', '150'], $this->limits($api)[1]); // adjusted: 75 + 75 - 0

        // a limit the user adds by hand afterwards, with the auto-budget amount, is left alone
        $repository = app(\FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface::class);
        $repository->setUser($user);
        $repository->store(['budget_id' => $web->id, 'currency_id' => $currency->id, 'start_date' => Carbon::parse('2026-12-25'), 'end_date' => Carbon::parse('2026-12-31'), 'amount' => '150']);
        self::assertSame(['2026-12-25', '2026-12-31', '150'], $this->limits($web)[2]);
    }

    /**
     * Upstream's update path (an existing budget gains an auto-budget, or its amount, type or period changes)
     * creates no limit at all, so there is nothing to replace: the cron makes the cycle limit.
     */
    public function testUpdatingAnAutoBudgetCreatesNoLimit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $user       = $this->cpUser('cp@example.com');
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $budget     = $repository->store(['name' => 'Plain', 'active' => true]);

        $repository->update($budget, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '60', 'auto_budget_period' => 'monthly']);
        $repository->update($budget, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_ROLLOVER->value, 'auto_budget_amount' => '70', 'auto_budget_period' => 'monthly']);
        self::assertSame([], $this->limits($budget));

        $this->runJob($user, '2026-10-27');
        self::assertSame([['2026-10-23', '2026-11-24', '70']], $this->limits($budget));
    }

    /**
     * Back from CP to 1M on 27 Oct (cycle 23 Oct - 24 Nov spans 1 Nov) through the preferences form: the cycle
     * limit is cut to 31 Oct, past cycles stay, and upstream's run on 1 Nov makes a November limit with no overlap.
     */
    public function testSwitchingBackFromCustomPeriodsCutsTheCurrentCycle(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $budget = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $weekly = $this->autoBudget($user, 'Weekly', AutoBudgetType::AUTO_BUDGET_RESET, '50', 'weekly');
        $this->runJob($user, '2026-09-25');
        $this->runJob($user, '2026-10-23');
        $this->runJob($user, '2026-10-26'); // a Monday: upstream's weekly limit
        self::assertSame([['2026-09-25', '2026-10-22', '100'], ['2026-10-23', '2026-11-24', '100']], $this->limits($budget));

        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $this->post(route('preferences.update'), ['viewRange' => '1M', 'ext_period' => ['anchor_day' => '25', 'business_day_rule' => 'previous', 'label' => 'range']])->assertRedirect();
        self::assertSame('1M', Preferences::getForUser($user, 'viewRange')->data);

        self::assertSame([['2026-09-25', '2026-10-22', '100'], ['2026-10-23', '2026-10-31', '100']], $this->limits($budget));
        self::assertSame([['2026-10-26', '2026-11-01', '50']], $this->limits($weekly)); // not a monthly auto-budget
        $this->assertNoOverlaps($budget);

        $this->runJob($user, '2026-10-28'); // idempotent
        $this->runJob($user, '2026-11-01'); // upstream's magic day
        self::assertSame([['2026-09-25', '2026-10-22', '100'], ['2026-10-23', '2026-10-31', '100'], ['2026-11-01', '2026-11-30', '100']], $this->limits($budget));
        $this->assertNoOverlaps($budget);

        // saving the preferences again on 1M changes nothing
        $this->post(route('preferences.update'), ['viewRange' => '1M'])->assertRedirect();
        self::assertCount(3, $this->limits($budget));
    }

    /**
     * Back from CP through the API, which the preferences middleware does not see: the daily job cuts the cycle
     * before upstream's run on the 1st, or right after it when the switch came too late; a limit set by hand
     * is never cut.
     */
    public function testTheDailyJobFixesOverlapLeftByAnApiSwitch(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $early  = $this->autoBudget($user, 'Early', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $late   = $this->autoBudget($user, 'Late', AutoBudgetType::AUTO_BUDGET_ROLLOVER, '100');
        $byHand = $this->autoBudget($user, 'By hand', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->limit($byHand, '2026-10-23', '2026-11-24', '90');
        $this->runJob($user, '2026-10-23');
        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($early));

        $this->actingAsApiUser($user);
        $this->putJson(route('api.v1.preferences.update', ['viewRange']), ['data' => '1M'])->assertOk();
        self::assertSame('1M', Preferences::getForUser($user, 'viewRange')->data);
        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($early)); // the API does not cut

        // the next daily run, still in October, cuts the cycle like the preferences form would
        $this->runJob($user, '2026-10-29');
        self::assertSame([['2026-10-23', '2026-10-31', '100']], $this->limits($early));

        // a leftover cycle that still runs into November (here: put back by hand) is cut on the 1st, after upstream's run
        $early->budgetlimits()->update(['end_date' => '2026-11-24']);
        $late->budgetlimits()->update(['end_date' => '2026-11-24']);
        $this->runJob($user, '2026-11-01');
        self::assertSame([['2026-10-23', '2026-10-31', '100'], ['2026-11-01', '2026-11-30', '100']], $this->limits($early));
        self::assertSame([['2026-10-23', '2026-10-31', '100'], ['2026-11-01', '2026-11-30', '100']], $this->limits($late));
        $this->assertNoOverlaps($early);
        $this->assertNoOverlaps($late);
        self::assertSame(['2026-10-23', '2026-11-24', '90'], $this->limits($byHand)[0]); // the user's limit stays
    }

    /**
     * Non-CP users: the ext job gives exactly what upstream's job gives, for every type and period.
     */
    public function testNonCustomPeriodUsersGetExactlyUpstream(): void
    {
        $results = [];
        foreach (['ext' => 'ext@example.com', 'upstream' => 'upstream@example.com'] as $mode => $email) {
            $user = $this->createUser($email);
            $this->actingAs($user);
            Preferences::setForUser($user, 'viewRange', '1M');
            Preferences::setForUser($user, 'extPeriod', self::CP); // stored, but not in use
            $account = $this->createAccount($user, 'Cheque');
            $budgets = [
                $this->autoBudget($user, 'Reset', AutoBudgetType::AUTO_BUDGET_RESET, '100'),
                $this->autoBudget($user, 'Rollover', AutoBudgetType::AUTO_BUDGET_ROLLOVER, '100'),
                $this->autoBudget($user, 'Adjusted', AutoBudgetType::AUTO_BUDGET_ADJUSTED, '100'),
                $this->autoBudget($user, 'Weekly', AutoBudgetType::AUTO_BUDGET_ROLLOVER, '25', 'weekly'),
                $this->autoBudget($user, 'Quarterly', AutoBudgetType::AUTO_BUDGET_RESET, '300', 'quarterly'),
            ];
            foreach (['2026-09-01', '2026-09-25', '2026-10-01', '2026-10-05', '2026-10-25', '2026-11-01', '2026-11-02', '2026-12-01'] as $date) {
                $job = 'ext' === $mode ? app(CreateAutoBudgetLimits::class, [Carbon::parse($date)]) : new CreateAutoBudgetLimits(Carbon::parse($date));
                self::assertSame('ext' === $mode, $job instanceof ExtCreateAutoBudgetLimits);
                $job->setDate(Carbon::parse($date, config('app.timezone')));
                $job->setUser($user);
                $job->handle();
                if ('2026-09-25' === $date) {
                    $this->spend($user, $account, $budgets[1], '2026-09-26', '40');
                    $this->spend($user, $account, $budgets[2], '2026-09-26', '140');
                }
            }
            $results[$mode] = array_map(fn (Budget $budget): array => $this->limits($budget), $budgets);
        }
        self::assertSame($results['upstream'], $results['ext']);
        self::assertSame([['2026-09-01', '2026-09-30', '100'], ['2026-10-01', '2026-10-31', '160'], ['2026-11-01', '2026-11-30', '260'], ['2026-12-01', '2026-12-31', '360']], $results['ext'][1]);
    }

    private function cpUser(string $email): User
    {
        $user = $this->createUser($email);
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', 'CP');
        Preferences::setForUser($user, 'extPeriod', self::CP);

        return $user;
    }

    /**
     * The API guard is Passport's; CI has no OAuth keys, so this test makes a throwaway pair.
     */
    private function actingAsApiUser(User $user): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        Passport::actingAs($user);
    }

    private function autoBudget(User $user, string $name, AutoBudgetType $type, string $amount, string $period = 'monthly'): Budget
    {
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $budget     = $repository->store(['name' => $name, 'active' => true]);

        $autoBudget                          = new AutoBudget();
        $autoBudget->budget()->associate($budget);
        $autoBudget->transaction_currency_id = Amount::getPrimaryCurrencyByUserGroup($user->userGroup)->id;
        $autoBudget->auto_budget_type        = $type->value;
        $autoBudget->amount                  = $amount;
        $autoBudget->period                  = $period;
        $autoBudget->save();

        return $budget;
    }

    private function limit(Budget $budget, string $start, string $end, string $amount): void
    {
        $limit                          = new BudgetLimit();
        $limit->budget_id               = $budget->id;
        $limit->transaction_currency_id = Amount::getPrimaryCurrencyByUserGroup($budget->user->userGroup)->id;
        $limit->start_date              = Carbon::parse($start, config('app.timezone'))->startOfDay();
        $limit->end_date                = Carbon::parse($end, config('app.timezone'))->endOfDay();
        $limit->amount                  = $amount;
        $limit->save();
    }

    private function runJob(User $user, string $date): void
    {
        $day = Carbon::parse($date.' 03:00:00', config('app.timezone'));
        $job = app(CreateAutoBudgetLimits::class, [$day]);
        $job->setDate($day);
        $job->setUser($user);
        $job->handle();
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> [start, end, amount] by start date
     */
    private function limits(Budget $budget): array
    {
        return $budget->budgetlimits()->orderBy('start_date')->get()->map(static fn (BudgetLimit $limit): array => [
            $limit->start_date->format('Y-m-d'),
            $limit->end_date->format('Y-m-d'),
            rtrim(rtrim(bcadd((string) $limit->amount, '0', 2), '0'), '.'),
        ])->all();
    }

    private function assertNoOverlaps(Budget $budget): void
    {
        $limits = $this->limits($budget);
        for ($i = 1; $i < count($limits); ++$i) {
            self::assertLessThan($limits[$i][0], $limits[$i - 1][1], sprintf('%s overlaps %s', implode('/', $limits[$i - 1]), implode('/', $limits[$i])));
        }
    }

    private function spend(User $user, Account $account, Budget $budget, string $date, string $amount): void
    {
        $currency   = Amount::getPrimaryCurrencyByUserGroup($user->userGroup);
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($user);
        $repository->setUserGroup($user->userGroup);
        $transaction = [
            'type'             => 'withdrawal',
            'date'             => Carbon::parse($date.' 12:00:00', config('app.timezone')),
            'order'            => 0,
            'currency_id'      => $currency->id,
            'currency_code'    => $currency->code,
            'amount'           => $amount,
            'description'      => 'Spent on '.$budget->name,
            'source_id'        => $account->id,
            'destination_name' => 'Synthetic Shop',
            'budget_id'        => $budget->id,
            'reconciled'       => false,
            'tags'             => [],
        ];
        foreach (['foreign_currency_id', 'foreign_currency_code', 'foreign_amount', 'source_name', 'source_iban', 'source_number', 'source_bic', 'destination_id',
            'destination_iban', 'destination_number', 'destination_bic', 'budget_name', 'category_id', 'category_name', 'bill_id', 'bill_name',
            'piggy_bank_id', 'piggy_bank_name', 'notes', 'internal_reference', 'external_id', 'original_source', 'recurrence_id', 'bunq_payment_id',
            'external_url', 'sepa_cc', 'sepa_ct_op', 'sepa_ct_id', 'sepa_db', 'sepa_country', 'sepa_ep', 'sepa_ci', 'sepa_batch_id', 'interest_date',
            'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date', 'latitude', 'longitude', 'zoom_level'] as $key) {
            $transaction[$key] = null;
        }
        $repository->store([
            'user' => $user, 'user_group' => $user->userGroup, 'group_title' => null, 'error_if_duplicate_hash' => false, 'batch_submission' => false,
            'apply_rules' => false, 'fire_webhooks' => false, 'transactions' => [$transaction],
        ]);
    }
}
