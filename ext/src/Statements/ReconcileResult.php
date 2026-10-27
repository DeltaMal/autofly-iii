<?php

/*
 * ReconcileResult.php
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

use FireflyIII\Ext\Models\StatementLine as StatementLineModel;

final readonly class ReconcileResult
{
    /**
     * @param list<LineOutcome>                                                                                               $lines
     * @param list<array{journal_id: int, group_id: int, date: string, amount: string, description: string, kind: string}> $fireflyOnly
     * @param array<string, string>                                                                                          $breakdown
     */
    public function __construct(public array $lines, public array $fireflyOnly, public array $breakdown) {}

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [StatementLineModel::MATCHED => 0, StatementLineModel::MISSING => 0, StatementLineModel::PROPOSAL => 0, StatementLineModel::CREATED => 0, StatementLineModel::IGNORED => 0];
        foreach ($this->lines as $line) {
            ++$counts[$line->status];
        }
        foreach (['pending', 'reversed_pair', 'unexplained'] as $kind) {
            $counts['firefly_'.$kind] = count(array_filter($this->fireflyOnly, static fn (array $j): bool => $kind === $j['kind']));
        }

        return $counts;
    }
}
