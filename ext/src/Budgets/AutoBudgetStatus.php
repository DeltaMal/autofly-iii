<?php

/*
 * AutoBudgetStatus.php
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

namespace FireflyIII\Ext\Budgets;

use Carbon\Carbon;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\User;

/**
 * What the auto-budget job last did for a user, shown on /ext/periods: the user preference "extAutoBudgetStatus".
 *
 *   {"job": {"at": "...", "disabled": false, "created": 1, "adjusted": 0, "cut": 0, "removed": 0},
 *    "latest": {"at": "...", "source": "edit", "created": 0, "adjusted": 1, "cut": 0, "removed": 0}}
 *
 * "job" is the last daily run; "latest" is the last change made outside it (switching the view range, a new
 * budget, editing an auto-budget).
 */
final class AutoBudgetStatus
{
    public const string PREFERENCE = 'extAutoBudgetStatus';
    public const array SOURCES     = ['preferences', 'new_budget', 'edit'];

    /**
     * @param array{created: int, adjusted: int, cut: int, removed: int} $changes
     */
    public static function recordJob(User $user, Carbon $at, array $changes, bool $disabled = false): void
    {
        self::write($user, 'job', ['at' => $at->toIso8601String(), 'disabled' => $disabled] + $changes);
    }

    /**
     * Only called when something changed.
     *
     * @param array{created: int, adjusted: int, cut: int, removed: int} $changes
     */
    public static function recordChange(User $user, string $source, array $changes): void
    {
        if (0 === array_sum($changes)) {
            return;
        }
        self::write($user, 'latest', ['at' => now(config('app.timezone'))->toIso8601String(), 'source' => $source] + $changes);
    }

    /**
     * @return array{job?: array<string, mixed>, latest?: array<string, mixed>}
     */
    public static function get(User $user): array
    {
        $data = Preferences::getForUser($user, self::PREFERENCE, [])?->data;

        return is_array($data) ? $data : [];
    }

    private static function write(User $user, string $key, array $entry): void
    {
        $data       = self::get($user);
        $data[$key] = $entry;
        Preferences::setForUser($user, self::PREFERENCE, $data);
    }
}
