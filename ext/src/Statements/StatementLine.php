<?php

/*
 * StatementLine.php
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

namespace FireflyIII\Ext\Statements;

use Carbon\Carbon;

/**
 * One statement line, in posting order (index 0 = newest). Money as bcmath strings; amount is signed from
 * the account's point of view.
 */
final readonly class StatementLine
{
    public function __construct(
        public int $postingIndex,
        public Carbon $date,
        public string $description,
        public string $amount,
        public string $fee,
        public string $balance,
        public ?string $cardRef,
        public string $counterparty,
        public string $raw,
        public bool $isFeeLine = false,
    ) {}
}
