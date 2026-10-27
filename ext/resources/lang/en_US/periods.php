<?php

/*
 * periods.php
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

return [
    // isoFormat strings for period labels (PeriodCalendar::label()); month names follow the user's locale.
    'format_day_month'             => 'D MMM',
    'format_day_month_year'        => 'D MMM YYYY',
    'format_month_year'            => 'MMMM YYYY',
    'format_range'                 => ':start – :end',

    // preferences
    'pref_CP'                      => 'Custom period (e.g. salary cycle)',
    'anchor_day'                   => 'Period starts on day',
    'anchor_manual'                => 'Manual (only the starts you close)',
    'anchor_last_day'              => 'Last day',
    'business_day_rule'            => 'If the start falls on a weekend',
    'rule_none'                    => 'Keep it on the weekend',
    'rule_previous'                => 'Move to the previous business day',
    'rule_next'                    => 'Move to the next business day',
    'label'                        => 'Period name',
    'label_range'                  => 'Date range (25 Oct – 24 Nov 2026)',
    'label_start_month'            => 'Month it starts in (October 2026)',
    'label_end_month'              => 'Month it ends in (November 2026)',
    'invalid_settings'             => 'The custom period settings were not saved: :errors',

    // periods page
    'title'                        => 'Periods',
    'current_and_next'             => 'Current period and the next six',
    'period'                       => 'Period',
    'start'                        => 'Start',
    'end'                          => 'End',
    'days'                         => 'Days',
    'explicit_starts'              => 'Explicit period starts',
    'no_explicit_starts'           => 'No explicit starts yet.',
    'name'                         => 'Name',
    'delete'                       => 'Delete',
    'close_period'                 => 'Close current period',
    'close_period_help'            => 'Start a new period on this date, e.g. when your salary arrives early. The new period takes effect immediately.',
    'date'                         => 'Date',
    'optional_name'                => 'Name (optional)',
    'closed'                       => 'A new period starts on :date.',
    'start_deleted'                => 'The explicit start on :date was removed.',
    'not_active'                   => 'Your view range is not "custom period". These periods are used once you pick it in the preferences.',
    'settings_link'                => 'Change the custom period settings',
    'mode_anchored'                => 'Starts on day :day of each month',
    'mode_manual'                  => 'Manual periods',
    'manual_no_starts'             => 'Manual mode has no period starts yet, so periods fall back to calendar months. Close the current period (below) to add the first start date.',

    // auto-budget job options (/ext/periods)
    'ab_title'                     => 'Auto-budget job',
    'ab_intro'                     => 'What the daily auto-budget job, and saving a budget or your preferences, may do to your budget limits. It only ever changes limits that an auto-budget made. A limit you set yourself is never changed, cut or removed.',
    'ab_enabled'                   => 'Align monthly auto-budgets with my custom period',
    'ab_enabled_help'              => 'On: while your view range is "custom period", each monthly auto-budget gets one limit per custom period instead of per calendar month, with the same reset, rollover and adjusted rules. Off: monthly auto-budgets always get calendar-month limits, like standard Firefly III, and none of the options below do anything.',
    'ab_take_over'                 => 'Take over calendar-month limits when I switch to custom periods',
    'ab_take_over_help'            => 'On: the first run after you switch shortens auto-budget-made limits that overlap the current custom period so they end the day before it, or removes them if they start inside it, and then creates the custom period limit. Off: they stay, and the custom period they overlap gets no limit; the first custom period that overlaps none of them gets its limit as usual.',
    'ab_new_budget_immediate'      => 'Give a new budget its custom period limit at once',
    'ab_new_budget_immediate_help' => 'On: when you create a budget with a monthly auto-budget while on custom periods, the calendar-month limit Firefly III makes is turned into the current custom period\'s limit straight away. Off: that calendar-month limit stays until the job takes it over (if the option above is on).',
    'ab_edit_immediate'            => 'Apply auto-budget changes to the current period at once',
    'ab_edit_immediate_help'       => 'On: when you add an auto-budget to a budget, or change its amount, type, period or currency, while on custom periods, the current custom period\'s limit is created, or its amount recalculated, straight away, but only if an auto-budget made that limit. Off: nothing changes until the next daily run, which only creates a limit when the period has none.',
    'ab_cut_on_leave'              => 'Cut the current period short when I switch back to calendar months',
    'ab_cut_on_leave_help'         => 'On: when you change your view range from "custom period" to anything else, the current custom period\'s auto-budget limit is cut to end on the last day of this calendar month, so the calendar-month limit from the 1st does not overlap it. Off: it keeps its end date, and from the 1st both limits cover the same days.',
    'ab_gap_fill'                  => 'After switching back, fill the days up to the 1st',
    'ab_gap_fill_help'             => 'When a custom period ends before the end of the month you switch back in (for example it ended on the 22nd and you switch on the 23rd), the days from the next day up to the end of that month have no limit. The job can give them one. Only days no other limit covers are filled.',
    'ab_gap_pro_rata'              => 'Part of the monthly amount (amount × days left ÷ days in the month)',
    'ab_gap_full'                  => 'The full monthly amount',
    'ab_gap_none'                  => 'Leave them without a limit',
    'ab_save'                      => 'Save auto-budget options',
    'ab_saved'                     => 'The auto-budget options were saved.',
    'ab_last_run'                  => 'Last daily run',
    'ab_last_change'               => 'Last change outside the daily run',
    'ab_never'                     => 'Not yet.',
    'ab_disabled_run'              => 'Ran as standard Firefly III (aligning is off).',
    'ab_counts'                    => ':created created, :adjusted adjusted, :cut cut short, :removed removed',
    'ab_source_preferences'        => 'switching view range',
    'ab_source_new_budget'         => 'a new budget',
    'ab_source_edit'               => 'an auto-budget change',

    // reports page
    'report_cycles'                => 'Custom periods (opens the default report)',
];
