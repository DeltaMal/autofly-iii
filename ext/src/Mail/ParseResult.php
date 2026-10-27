<?php

/*
 * ParseResult.php
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

namespace FireflyIII\Ext\Mail;

final readonly class ParseResult
{
    public const string TRANSACTIONS = 'transactions';
    public const string IGNORED      = 'ignored';
    public const string UNMATCHED    = 'unmatched';

    /**
     * @param list<ParsedTransaction> $transactions
     * @param list<string>            $ignored
     * @param list<string>            $unmatched
     */
    public function __construct(public array $transactions, public array $ignored, public array $unmatched) {}

    /** unmatched if anything is unmatched, else transactions if there are any, else ignored. */
    public function outcome(): string
    {
        if ([] !== $this->unmatched) {
            return self::UNMATCHED;
        }

        return [] !== $this->transactions ? self::TRANSACTIONS : self::IGNORED;
    }
}
