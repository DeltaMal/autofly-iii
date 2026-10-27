<?php

/*
 * ParsedStatement.php
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

final readonly class ParsedStatement
{
    /**
     * @param list<string>        $warnings
     * @param list<StatementLine> $lines    posting order, newest first
     */
    public function __construct(
        public string $bank,
        public string $parser,
        public string $accountNumber,
        public Carbon $statementDate,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $openingBalance,
        public string $closingBalance,
        public ?string $availableBalance,
        public ?string $currentBalance,
        public bool $chainValid,
        public array $warnings,
        public array $lines,
    ) {}

    public function sumOfAmounts(): string
    {
        $sum = '0.00';
        foreach ($this->lines as $line) {
            if (!$line->isFeeLine) {
                $sum = bcadd($sum, $line->amount, 2);
            }
        }

        return $sum;
    }
}
