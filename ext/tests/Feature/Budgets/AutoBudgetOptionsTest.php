<?php

/*
 * AutoBudgetOptionsTest.php
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
use FireflyIII\Ext\Budgets\AutoBudgetLimitRegistry;
use FireflyIII\Ext\Budgets\AutoBudgetOptions;
use FireflyIII\Ext\Budgets\AutoBudgetStatus;
use FireflyIII\Ext\Budgets\CustomPeriodAutoBudgets;
use FireflyIII\Ext\Budgets\InitialAutoBudgetLimit;
use FireflyIII\Ext\Console\BackfillAutoBudgetLimits;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The auto-budget job options on /ext/periods (ext/docs/01, "Auto-budget job options"), each on and off, the
 * gap fill modes, the record of upstream's first limits and the backfill command. The "on" side of the options
 * that existed before is covered by CustomPeriodAutoBudgetsTest with the defaults.
 *
 * Cycles (anchor 25, rule "previous"): 25 Aug - 24 Sep, 25 Sep - 22 Oct, 23 Oct - 24 Nov, 25 Nov - 24 Dec 2026.
 *
 * @internal
 */
#[CoversClass(AutoBudgetOptions::class)]
#[CoversClass(AutoBudgetLimitRegistry::class)]
#[CoversClass(AutoBudgetStatus::class)]
#[CoversClass(CustomPeriodAutoBudgets::class)]
#[CoversClass(InitialAutoBudgetLimit::class)]
#[CoversClass(BackfillAutoBudgetLimits::class)]
final class AutoBudgetOptionsTest extends FeatureTestCase
{
    use AutoBudgetTestHelpers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testThePeriodsPageExplainsAndSavesTheOptions(): void
    {
        $user = $this->cpUser('cp@example.com');
        Preferences::setForUser($user, 'extPeriod', self::CP + ['starts' => [['date' => '2026-10-23', 'name' => 'Early']]]);

        $page = $this->get(route('ext.periods.index'))->assertOk();
        foreach (['ab_title', 'ab_intro', 'ab_enabled', 'ab_take_over', 'ab_new_budget_immediate', 'ab_edit_immediate', 'ab_cut_on_leave', 'ab_gap_fill', 'ab_gap_pro_rata', 'ab_never'] as $key) {
            $page->assertSee((string) trans('ext::periods.'.$key));
        }
        foreach (AutoBudgetOptions::FLAGS as $flag) {
            $page->assertSee((string) trans('ext::periods.ab_'.$flag.'_help'));
        }
        self::assertSame(AutoBudgetOptions::defaults(), CustomPeriodAutoBudgets::options($user));
        self::assertEquals(new AutoBudgetOptions(true, true, true, true, true, 'pro_rata'), AutoBudgetOptions::defaults());

        $this->post(route('ext.periods.auto-budgets'), [
            'enabled' => '1', 'take_over' => '0', 'new_budget_immediate' => '1', 'edit_immediate' => '0', 'cut_on_leave' => '1', 'gap_fill' => 'full',
        ])->assertRedirect(route('ext.periods.index'));
        $stored = Preferences::getForUser($user, 'extPeriod')->data;
        self::assertSame(['enabled' => true, 'take_over' => false, 'new_budget_immediate' => true, 'edit_immediate' => false, 'cut_on_leave' => true, 'gap_fill' => 'full'], $stored['auto_budgets']);
        self::assertSame([['date' => '2026-10-23', 'name' => 'Early']], $stored['starts']);
        self::assertSame(25, $stored['anchor_day']);

        // invalid: nothing changes
        $this->post(route('ext.periods.auto-budgets'), ['enabled' => '1', 'take_over' => '1', 'new_budget_immediate' => '1', 'edit_immediate' => '1', 'cut_on_leave' => '1', 'gap_fill' => 'half'])
            ->assertSessionHasErrors('gap_fill');
        $this->post(route('ext.periods.auto-budgets'), ['gap_fill' => 'none'])->assertSessionHasErrors('enabled');
        self::assertSame('full', Preferences::getForUser($user, 'extPeriod')->data['auto_budgets']['gap_fill']);

        // upstream's preferences form (the ext middleware saves the period fields) keeps the options
        $this->post(route('preferences.update'), ['viewRange' => 'CP', 'ext_period' => ['anchor_day' => '20', 'business_day_rule' => 'none', 'label' => 'range']])->assertRedirect();
        $stored = Preferences::getForUser($user, 'extPeriod')->data;
        self::assertSame(20, $stored['anchor_day']);
        self::assertFalse($stored['auto_budgets']['take_over']);
        self::assertSame('full', $stored['auto_budgets']['gap_fill']);

        // the page shows the saved state
        $page = $this->get(route('ext.periods.index'))->assertOk();
        $page->assertSee('name="enabled" value="1" checked', false);
        $page->assertDontSee('name="take_over" value="1" checked', false);
        $page->assertSee('<option value="full" selected', false);
    }

    public function testBrokenStoredOptionsFallBackToTheDefaults(): void
    {
        self::assertEquals(AutoBudgetOptions::defaults(), AutoBudgetOptions::fromArray('garbage'));
        self::assertEquals(new AutoBudgetOptions(false, true, true, true, false, 'pro_rata'), AutoBudgetOptions::fromArray(['enabled' => false, 'cut_on_leave' => '0', 'gap_fill' => 'half']));
    }

    public function testThePageShowsWhatTheJobLastChanged(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->autoBudget($user, 'Fuel', AutoBudgetType::AUTO_BUDGET_RESET, '50');
        $this->runJob($user, '2026-10-23');
        $status = AutoBudgetStatus::get($user);
        self::assertSame([false, 2, 0, 0, 0], [$status['job']['disabled'], $status['job']['created'], $status['job']['adjusted'], $status['job']['cut'], $status['job']['removed']]);
        $this->get(route('ext.periods.index'))->assertOk()->assertSee((string) trans('ext::periods.ab_counts', ['created' => 2, 'adjusted' => 0, 'cut' => 0, 'removed' => 0]));

        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $this->post(route('preferences.update'), ['viewRange' => '1M', 'ext_period' => ['anchor_day' => '25', 'business_day_rule' => 'previous', 'label' => 'range']])->assertRedirect();
        $status = AutoBudgetStatus::get($user);
        self::assertSame(['preferences', 0, 0, 2, 0], [$status['latest']['source'], $status['latest']['created'], $status['latest']['adjusted'], $status['latest']['cut'], $status['latest']['removed']]);
        $this->get(route('ext.periods.index'))->assertOk()
            ->assertSee((string) trans('ext::periods.ab_source_preferences'))
            ->assertSee((string) trans('ext::periods.ab_counts', ['created' => 0, 'adjusted' => 0, 'cut' => 2, 'removed' => 0]))
        ;
    }

    /**
     * "enabled" off: monthly auto-budgets get calendar months even on CP, and nothing else of ext runs.
     */
    public function testTurningAlignmentOffGivesUpstreamEvenOnCustomPeriods(): void
    {
        $user     = $this->cpUser('cp@example.com');
        $budget   = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->runJob($user, '2026-10-23'); // still on: the cycle limit
        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($budget));

        $this->options($user, ['enabled' => false]);
        $this->runJob($user, '2026-11-01'); // upstream's calendar month, next to the cycle
        self::assertSame([['2026-10-23', '2026-11-24', '100'], ['2026-11-01', '2026-11-30', '100']], $this->limits($budget));
        self::assertTrue(AutoBudgetStatus::get($user)['job']['disabled']);

        // a new budget keeps upstream's calendar limit (still recorded), an edit adds nothing
        Carbon::setTestNow(Carbon::parse('2026-11-03 09:00:00', config('app.timezone')));
        $created    = $this->storeBudget($user, 'Fuel', 'reset', '80');
        self::assertSame([['2026-11-01', '2026-11-30', '80']], $this->limits($created));
        self::assertTrue(AutoBudgetLimitRegistry::isRecorded($created->budgetlimits()->first()));
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $plain      = $repository->store(['name' => 'Plain', 'active' => true]);
        $repository->update($plain, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '60', 'auto_budget_period' => 'monthly']);
        self::assertSame([], $this->limits($plain));

        // switching back cuts nothing
        $this->post(route('preferences.update'), ['viewRange' => '1M', 'ext_period' => ['anchor_day' => '25', 'business_day_rule' => 'previous', 'label' => 'range']])->assertRedirect();
        self::assertSame(['2026-10-23', '2026-11-24', '100'], $this->limits($budget)[0]);
        $this->runJob($user, '2026-11-04');
        self::assertSame(['2026-10-23', '2026-11-24', '100'], $this->limits($budget)[0]);
    }

    /**
     * "take_over" off: calendar limits made by auto-budgets stay after the switch to CP; the cycles they overlap
     * get no limit, and the first cycle clear of them gets one.
     */
    public function testWithoutTakeOverCalendarLimitsStay(): void
    {
        $user   = $this->createUser('switch@example.com');
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', '1M');
        $budget = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->runJob($user, '2026-09-01');
        $this->runJob($user, '2026-10-01');

        Preferences::setForUser($user, 'viewRange', 'CP');
        $this->options($user, ['take_over' => false]);
        $this->runJob($user, '2026-10-15');
        $this->runJob($user, '2026-10-23');
        self::assertSame([['2026-09-01', '2026-09-30', '100'], ['2026-10-01', '2026-10-31', '100']], $this->limits($budget));

        $this->runJob($user, '2026-11-25');
        self::assertSame([['2026-09-01', '2026-09-30', '100'], ['2026-10-01', '2026-10-31', '100'], ['2026-11-25', '2026-12-24', '100']], $this->limits($budget));
    }

    /**
     * "new_budget_immediate" off: upstream's first calendar limit stays (recorded), and the daily job takes it over.
     */
    public function testWithoutNewBudgetImmediateTheJobTakesTheFirstLimitOver(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $user     = $this->cpUser('cp@example.com');
        $this->options($user, ['new_budget_immediate' => false]);
        $currency = Amount::getPrimaryCurrencyByUserGroup($user->userGroup);
        $this->post(route('budgets.store'), [
            'name' => 'Web budget', 'active' => '1', 'auto_budget_type' => '2', 'auto_budget_currency_id' => (string) $currency->id,
            'auto_budget_amount' => '150', 'auto_budget_period' => 'monthly',
        ])->assertRedirect();
        $web      = Budget::query()->where('name', 'Web budget')->firstOrFail();
        self::assertSame([['2026-10-01', '2026-10-31', '150']], $this->limits($web));
        self::assertTrue(AutoBudgetLimitRegistry::isRecorded($web->budgetlimits()->first()));

        $this->runJob($user, '2026-10-27');
        self::assertSame([['2026-10-01', '2026-10-22', '150'], ['2026-10-23', '2026-11-24', '150']], $this->limits($web));
    }

    /**
     * "edit_immediate" off: an auto-budget change waits for the daily run, which only creates a missing limit.
     */
    public function testWithoutEditImmediateChangesWaitForTheJob(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $user       = $this->cpUser('cp@example.com');
        $this->options($user, ['edit_immediate' => false]);
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $budget     = $repository->store(['name' => 'Plain', 'active' => true]);

        $repository->update($budget, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '60', 'auto_budget_period' => 'monthly']);
        self::assertSame([], $this->limits($budget));
        $this->runJob($user, '2026-10-27');
        self::assertSame([['2026-10-23', '2026-11-24', '60']], $this->limits($budget));

        $repository->update($budget, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '70']);
        $this->runJob($user, '2026-10-28');
        self::assertSame([['2026-10-23', '2026-11-24', '60']], $this->limits($budget));
        $this->runJob($user, '2026-11-25');
        self::assertSame(['2026-11-25', '2026-12-24', '70'], $this->limits($budget)[1]);
    }

    /**
     * "edit_immediate" on: the job's cycle limit follows the change; a hand-set limit on the cycle never does.
     */
    public function testEditImmediateOnlyAdjustsAutoBudgetMadeLimits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $user       = $this->cpUser('cp@example.com');
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $made       = $this->autoBudget($user, 'Made', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $byHand     = $this->autoBudget($user, 'By hand', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->limit($byHand, '2026-10-23', '2026-11-24', '90');
        $this->runJob($user, '2026-10-27');
        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($made));

        $repository->update($made, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '120']);
        $repository->update($byHand, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '120']);
        self::assertSame([['2026-10-23', '2026-11-24', '120']], $this->limits($made));
        self::assertSame([['2026-10-23', '2026-11-24', '90']], $this->limits($byHand));
        self::assertSame(['edit', 1], [AutoBudgetStatus::get($user)['latest']['source'], AutoBudgetStatus::get($user)['latest']['adjusted']]);

        // a budget that only changes name or notes changes no limit
        $repository->update($made, ['name' => 'Made (renamed)', 'auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '120']);
        self::assertSame([['2026-10-23', '2026-11-24', '120']], $this->limits($made));
    }

    /**
     * "cut_on_leave" off: the cycle keeps its end after switching back, so upstream's limit from the 1st overlaps it.
     */
    public function testWithoutCutOnLeaveTheCycleKeepsItsEnd(): void
    {
        $user   = $this->cpUser('cp@example.com');
        $this->options($user, ['cut_on_leave' => false]);
        $budget = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->runJob($user, '2026-10-23');

        Carbon::setTestNow(Carbon::parse('2026-10-27 09:00:00', config('app.timezone')));
        $this->post(route('preferences.update'), ['viewRange' => '1M', 'ext_period' => ['anchor_day' => '25', 'business_day_rule' => 'previous', 'label' => 'range']])->assertRedirect();
        self::assertSame([['2026-10-23', '2026-11-24', '100']], $this->limits($budget));
        $this->runJob($user, '2026-11-01');
        self::assertSame([['2026-10-23', '2026-11-24', '100'], ['2026-11-01', '2026-11-30', '100']], $this->limits($budget));
    }

    public static function gapFillModes(): iterable
    {
        yield 'pro rata' => ['pro_rata', '29.03']; // 100 x 9 / 31
        yield 'full' => ['full', '100'];
        yield 'none' => ['none', null];
    }

    /**
     * The cycle ended on 22 Oct and the user switches back on 23 Oct (before that day's run), so 23 - 31 Oct have
     * no limit. Idempotent, and upstream's run on 1 Nov overlaps nothing.
     */
    #[DataProvider('gapFillModes')]
    public function testGapFillAfterSwitchingBack(string $mode, ?string $amount): void
    {
        $user   = $this->cpUser('cp@example.com');
        $this->options($user, ['gap_fill' => $mode]);
        $budget = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_ROLLOVER, '100');
        $weekly = $this->autoBudget($user, 'Weekly', AutoBudgetType::AUTO_BUDGET_RESET, '50', 'weekly');
        $this->runJob($user, '2026-09-25');

        Carbon::setTestNow(Carbon::parse('2026-10-23 07:00:00', config('app.timezone')));
        $this->post(route('preferences.update'), ['viewRange' => '1M', 'ext_period' => ['anchor_day' => '25', 'business_day_rule' => 'previous', 'label' => 'range']])->assertRedirect();
        $expected = [['2026-09-25', '2026-10-22', '100']];
        if (null !== $amount) {
            $expected[] = ['2026-10-23', '2026-10-31', $amount];
        }
        self::assertSame($expected, $this->limits($budget));
        self::assertSame([], $this->limits($weekly)); // only monthly auto-budgets

        // idempotent: saving again and the daily runs add nothing
        $this->post(route('preferences.update'), ['viewRange' => '1M'])->assertRedirect();
        $this->runJob($user, '2026-10-23');
        $this->runJob($user, '2026-10-30');
        self::assertSame($expected, $this->limits($budget));

        $this->runJob($user, '2026-11-01');
        $limits = $this->limits($budget);
        self::assertSame(['2026-11-01', '2026-11-30'], array_slice($limits[count($limits) - 1], 0, 2));
        $this->assertNoOverlaps($budget);
        self::assertTrue((bool) $budget->budgetlimits()->where('start_date', '2026-11-01')->first()->generated);
    }

    /**
     * A switch through the API: the daily job fills the gap; days that a limit already covers are left alone.
     */
    public function testTheDailyJobFillsTheGapAfterAnApiSwitch(): void
    {
        $user    = $this->cpUser('cp@example.com');
        $budget  = $this->autoBudget($user, 'Groceries', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $partial = $this->autoBudget($user, 'Partial', AutoBudgetType::AUTO_BUDGET_RESET, '100');
        $this->runJob($user, '2026-09-25');
        $this->limit($partial, '2026-10-23', '2026-10-25', '10');

        Preferences::setForUser($user, 'viewRange', '1M');
        $this->runJob($user, '2026-10-24');
        self::assertSame([['2026-09-25', '2026-10-22', '100'], ['2026-10-23', '2026-10-31', '29.03']], $this->limits($budget));
        self::assertSame([['2026-09-25', '2026-10-22', '100'], ['2026-10-23', '2026-10-25', '10'], ['2026-10-26', '2026-10-31', '19.35']], $this->limits($partial)); // 100 x 6 / 31
        $this->runJob($user, '2026-10-25');
        self::assertCount(2, $this->limits($budget));
        self::assertCount(3, $this->limits($partial));
    }

    public function testProRataRoundsToTheCurrencyDecimals(): void
    {
        $twoDecimals                 = new TransactionCurrency();
        $twoDecimals->decimal_places = 2;
        $noDecimals                  = new TransactionCurrency();
        $noDecimals->decimal_places  = 0;
        self::assertSame('29.03', CustomPeriodAutoBudgets::proRata('100', 9, 31, $twoDecimals));
        self::assertSame('6.67', CustomPeriodAutoBudgets::proRata('10', 2, 3, $twoDecimals));
        self::assertSame('3.33', CustomPeriodAutoBudgets::proRata('10', 1, 3, $twoDecimals));
        self::assertSame('333.33', CustomPeriodAutoBudgets::proRata('1000', 10, 30, $twoDecimals));
        self::assertSame('29', CustomPeriodAutoBudgets::proRata('100', 9, 31, $noDecimals));
        self::assertSame('100.00', CustomPeriodAutoBudgets::proRata('100', 31, 31, $twoDecimals));
    }

    /**
     * Upstream's first limit is recorded for every user, whatever the view range and options; other limits are not,
     * and a deleted limit leaves no record.
     */
    public function testUpstreamsFirstLimitIsRecordedForEveryUser(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', config('app.timezone'))); // a Wednesday
        $user    = $this->createUser('calendar@example.com');
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', '1M');
        $this->options($user, ['enabled' => false]);

        $monthly = $this->storeBudget($user, 'Monthly', 'reset', '80');
        $weekly  = $this->storeBudget($user, 'Weekly', 'rollover', '20', 'weekly');
        self::assertSame([['2026-10-01', '2026-10-31', '80']], $this->limits($monthly));
        self::assertSame([['2026-10-12', '2026-10-18', '20']], $this->limits($weekly));
        self::assertTrue(AutoBudgetLimitRegistry::isRecorded($monthly->budgetlimits()->first()));
        self::assertTrue(AutoBudgetLimitRegistry::isRecorded($weekly->budgetlimits()->first()));

        $limits  = app(BudgetLimitRepositoryInterface::class);
        $limits->setUser($user);
        $byHand  = $limits->store(['budget_id' => $monthly->id, 'currency_id' => $monthly->budgetlimits()->first()->transaction_currency_id, 'start_date' => Carbon::parse('2026-11-01'), 'end_date' => Carbon::parse('2026-11-30'), 'amount' => '80']);
        self::assertFalse(AutoBudgetLimitRegistry::isRecorded($byHand));
        self::assertSame(2, DB::table(AutoBudgetLimitRegistry::TABLE)->count());

        // ON DELETE CASCADE to budget_limits. SQLite only enforces it with PRAGMA foreign_keys, which cannot be
        // switched on inside RefreshDatabase's transaction, so there the test checks the constraint itself.
        $keys    = Schema::getForeignKeys(AutoBudgetLimitRegistry::TABLE);
        self::assertSame([['budget_limit_id'], 'budget_limits', 'cascade'], [$keys[0]['columns'], $keys[0]['foreign_table'], strtolower((string) $keys[0]['on_delete'])]);
        if ('sqlite' !== DB::connection()->getDriverName()) {
            $limits->destroyBudgetLimit($weekly->budgetlimits()->first());
            self::assertSame(1, DB::table(AutoBudgetLimitRegistry::TABLE)->count());
        }
    }

    /**
     * The backfill marks only limits that are beyond doubt upstream's first limit, supports --dry-run and is
     * idempotent. The marked limit is then taken over after a switch to CP.
     */
    public function testTheBackfillMarksOnlyLimitsBeyondDoubt(): void
    {
        $user    = $this->createUser('calendar@example.com');
        $this->actingAs($user);
        Preferences::setForUser($user, 'viewRange', '1M');
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00', config('app.timezone')));
        $old     = $this->storeBudget($user, 'Old', 'reset', '80');
        $changed = $this->storeBudget($user, 'Changed', 'reset', '50');
        $changed->budgetlimits()->update(['amount' => '55']); // the user changed the first limit
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);
        $later   = $repository->store(['name' => 'Later', 'active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-12 10:00:00', config('app.timezone')));
        $repository->update($later, ['auto_budget_type' => AutoBudgetType::AUTO_BUDGET_RESET->value, 'auto_budget_amount' => '40', 'auto_budget_period' => 'monthly']);
        $this->limit($later, '2026-09-01', '2026-09-30', '40'); // set by hand, the same shape and amount
        DB::table(AutoBudgetLimitRegistry::TABLE)->delete(); // as before ext recorded them

        $this->artisan('ext:auto-budgets:backfill', ['--dry-run' => true])
            ->expectsOutputToContain(sprintf('Would mark limit #%d of budget #%d', $old->budgetlimits()->first()->id, $old->id))
            ->expectsOutputToContain('Dry run: would mark 1 limit(s)')
            ->assertSuccessful()
        ;
        self::assertSame(0, DB::table(AutoBudgetLimitRegistry::TABLE)->count());

        $this->artisan('ext:auto-budgets:backfill')->expectsOutputToContain('Marked 1 limit(s)')->assertSuccessful();
        self::assertTrue(AutoBudgetLimitRegistry::isRecorded($old->budgetlimits()->first()));
        self::assertSame(1, DB::table(AutoBudgetLimitRegistry::TABLE)->count());
        $this->artisan('ext:auto-budgets:backfill')->expectsOutputToContain('Marked 0 limit(s)')->assertSuccessful();

        Preferences::setForUser($user, 'viewRange', 'CP');
        Preferences::setForUser($user, 'extPeriod', self::CP);
        $this->runJob($user, '2026-09-15');
        self::assertSame([['2026-08-25', '2026-09-24', '80']], $this->limits($old)); // 1 - 30 Sep started inside: removed
        self::assertSame([['2026-09-01', '2026-09-30', '55']], $this->limits($changed));
        self::assertSame([['2026-09-01', '2026-09-30', '40']], $this->limits($later));
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function options(User $user, array $options): void
    {
        $data                 = Preferences::getForUser($user, 'extPeriod', self::CP)->data;
        $data                 = is_array($data) ? $data : self::CP;
        $data['auto_budgets'] = array_merge(AutoBudgetOptions::defaults()->toArray(), $options);
        Preferences::setForUser($user, 'extPeriod', $data);
    }

    private function storeBudget(User $user, string $name, string $type, string $amount, string $period = 'monthly'): Budget
    {
        $repository = app(BudgetRepositoryInterface::class);
        $repository->setUser($user);

        return $repository->store([
            'name' => $name, 'active' => true, 'auto_budget_type' => $type, 'auto_budget_amount' => $amount, 'auto_budget_period' => $period,
            'currency_id' => Amount::getPrimaryCurrencyByUserGroup($user->userGroup)->id,
        ]);
    }
}
