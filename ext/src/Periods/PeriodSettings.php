<?php

/*
 * PeriodSettings.php
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

namespace FireflyIII\Ext\Periods;

/**
 * The user preference "extPeriod" (ext/docs/01-custom-periods.md).
 */
final readonly class PeriodSettings
{
    public const array BUSINESS_DAY_RULES = ['none', 'previous', 'next'];
    public const array LABEL_STYLES       = ['range', 'start_month', 'end_month'];

    /**
     * @param list<array{date: string, name?: null|string}> $starts explicit period starts (Y-m-d)
     */
    public function __construct(
        public ?int $anchorDay = 25,
        public string $businessDayRule = 'none',
        public string $label = 'range',
        public array $starts = [],
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Lenient: unknown or broken values fall back to the defaults, so a damaged preference never breaks a page.
     */
    public static function fromArray(mixed $data): self
    {
        if (!is_array($data)) {
            return self::defaults();
        }
        $anchor = array_key_exists('anchor_day', $data) ? $data['anchor_day'] : 25;
        $anchor = null === $anchor || '' === $anchor ? null : (int) $anchor;
        if (null !== $anchor && ($anchor < 1 || $anchor > 31)) {
            $anchor = 25;
        }
        $rule   = in_array($data['business_day_rule'] ?? 'none', self::BUSINESS_DAY_RULES, true) ? $data['business_day_rule'] ?? 'none' : 'none';
        $label  = in_array($data['label'] ?? 'range', self::LABEL_STYLES, true) ? $data['label'] ?? 'range' : 'range';
        $starts = [];
        foreach (is_array($data['starts'] ?? null) ? $data['starts'] : [] as $item) {
            $date = is_array($item) ? (string) ($item['date'] ?? '') : '';
            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                continue;
            }
            $name     = isset($item['name']) && '' !== trim((string) $item['name']) ? trim((string) $item['name']) : null;
            $starts[] = null === $name ? ['date' => $date] : ['date' => $date, 'name' => $name];
        }

        return new self($anchor, $rule, $label, $starts);
    }

    /**
     * @return array{anchor_day: null|int, business_day_rule: string, label: string, starts: list<array{date: string, name?: string}>}
     */
    public function toArray(): array
    {
        return [
            'anchor_day'        => $this->anchorDay,
            'business_day_rule' => $this->businessDayRule,
            'label'             => $this->label,
            'starts'            => $this->starts,
        ];
    }

    public function withStarts(array $starts): self
    {
        return new self($this->anchorDay, $this->businessDayRule, $this->label, array_values($starts));
    }
}
