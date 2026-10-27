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
    'format_day_month'       => 'D MMM',
    'format_day_month_year'  => 'D MMM YYYY',
    'format_month_year'      => 'MMMM YYYY',
    'format_range'           => ':start – :end',

    // preferences
    'pref_CP'                => 'Custom period (e.g. salary cycle)',
    'anchor_day'             => 'Period starts on day',
    'anchor_manual'          => 'Manual (only the starts you close)',
    'anchor_last_day'        => 'Last day',
    'business_day_rule'      => 'If the start falls on a weekend',
    'rule_none'              => 'Keep it on the weekend',
    'rule_previous'          => 'Move to the previous business day',
    'rule_next'              => 'Move to the next business day',
    'label'                  => 'Period name',
    'label_range'            => 'Date range (25 Oct – 24 Nov 2026)',
    'label_start_month'      => 'Month it starts in (October 2026)',
    'label_end_month'        => 'Month it ends in (November 2026)',
    'invalid_settings'       => 'The custom period settings were not saved: :errors',

    // periods page
    'title'                  => 'Periods',
    'current_and_next'       => 'Current period and the next six',
    'period'                 => 'Period',
    'start'                  => 'Start',
    'end'                    => 'End',
    'days'                   => 'Days',
    'explicit_starts'        => 'Explicit period starts',
    'no_explicit_starts'     => 'No explicit starts yet.',
    'name'                   => 'Name',
    'delete'                 => 'Delete',
    'close_period'           => 'Close current period',
    'close_period_help'      => 'Start a new period on this date, e.g. when your salary arrives early. The new period takes effect immediately.',
    'date'                   => 'Date',
    'optional_name'          => 'Name (optional)',
    'closed'                 => 'A new period starts on :date.',
    'start_deleted'          => 'The explicit start on :date was removed.',
    'not_active'             => 'Your view range is not "custom period". These periods are used once you pick it in the preferences.',
    'settings_link'          => 'Change the custom period settings',
    'mode_anchored'          => 'Starts on day :day of each month',
    'mode_manual'            => 'Manual periods',
];
