<?php

/*
 * TransactionRow.php
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

namespace FireflyIII\Ext\Import;

use Carbon\Carbon;
use FireflyIII\Models\Account;

/**
 * One transaction to store through TransactionWriter. Amounts are positive bcmath strings; $account is the
 * tracked account the row was seen on, $counterAccount a second tracked account (own-account transfer) or
 * null, in which case $counterpartyName is the opposing (expense/revenue) account.
 */
final readonly class TransactionRow
{
    /**
     * @param 'in'|'out'   $direction money into or out of $account
     * @param list<string> $tags
     */
    public function __construct(
        public Account $account,
        public string $direction,
        public string $amount,
        public Carbon $date,
        public string $description,
        public string $counterpartyName,
        public ?Account $counterAccount = null,
        public array $tags = [],
        public ?string $notes = null,
        public ?string $externalId = null,
        public ?string $internalReference = null,
        public bool $applyRules = true,
        public bool $reconciled = false,
    ) {}
}
