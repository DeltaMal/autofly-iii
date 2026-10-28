<?php

/*
 * AutoBudgetOptions.php
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

/**
 * What the auto-budget job does on custom periods, per user: the "auto_budgets" key of the extPeriod preference
 * (ext/docs/01-custom-periods.md, "Auto-budget job options"). Every behaviour is an option the user sees on
 * /ext/periods; the defaults are what the job did before the options existed, plus the two new behaviours.
 */
final readonly class AutoBudgetOptions
{
    public const array FLAGS     = ['enabled', 'take_over', 'new_budget_immediate', 'edit_immediate', 'cut_on_leave'];
    public const array GAP_FILLS = ['pro_rata', 'full', 'none'];

    public function __construct(
        public bool $enabled = true,
        public bool $takeOver = true,
        public bool $newBudgetImmediate = true,
        public bool $editImmediate = true,
        public bool $cutOnLeave = true,
        public string $gapFill = 'pro_rata',
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Lenient like PeriodSettings::fromArray(): missing or broken values fall back to the defaults.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            return self::defaults();
        }
        $flag = static function (string $key) use ($data): bool {
            $value = $data[$key] ?? true;

            return is_bool($value) ? $value : !in_array($value, [0, '0', 'false', 'off', ''], true);
        };
        $gap  = $data['gap_fill'] ?? 'pro_rata';

        return new self(
            $flag('enabled'),
            $flag('take_over'),
            $flag('new_budget_immediate'),
            $flag('edit_immediate'),
            $flag('cut_on_leave'),
            in_array($gap, self::GAP_FILLS, true) ? $gap : 'pro_rata',
        );
    }

    /**
     * @return array{enabled: bool, take_over: bool, new_budget_immediate: bool, edit_immediate: bool, cut_on_leave: bool, gap_fill: string}
     */
    public function toArray(): array
    {
        return [
            'enabled'              => $this->enabled,
            'take_over'            => $this->takeOver,
            'new_budget_immediate' => $this->newBudgetImmediate,
            'edit_immediate'       => $this->editImmediate,
            'cut_on_leave'         => $this->cutOnLeave,
            'gap_fill'             => $this->gapFill,
        ];
    }
}
