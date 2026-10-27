<?php

/*
 * StatementTestHelpers.php
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

namespace FireflyIII\Ext\Tests\Feature\Statements;

use Carbon\Carbon;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Import\TransactionRow;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Statements\StatementFile;
use FireflyIII\Ext\Statements\StatementImporter;
use FireflyIII\Ext\Statements\UploadOutcome;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;

/**
 * Builds FNB Transaction History layouts and email-like Firefly transactions for the matcher scenarios.
 */
trait StatementTestHelpers
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $rows [Y-m-d, description, signed amount], newest first
     */
    protected static function layout(array $rows, string $closing, string $accountNumber = '62012345678', string $statementDate = '2025-07-14'): string
    {
        $fmt     = static fn (string $v, bool $balance): string => ($balance ? '' : (str_starts_with($v, '-') ? '-' : '')).number_format((float) ltrim($v, '-'), 2, '.', ',').' '.(str_starts_with($v, '-') ? 'DR' : 'CR');
        $day     = static fn (string $d): string => Carbon::parse($d)->format('d M Y');
        $out     = [
            '.', 'Transaction History', '.',
            'Nickname:                                 FNB',
            '                                          Current Account',
            'Selected Account:                         '.$accountNumber,
            'Date:                                     '.$day($statementDate),
            'Available Balance:                         '.$fmt($closing, true),
            'Current Balance:                           '.$fmt($closing, true),
            '', '.',
            'Date                          Description             Service Fee             Amount                                                 Balance',
        ];
        $balance = $closing;
        foreach ($rows as [$date, $description, $amount]) {
            $out[]   = sprintf('%s                   %-30s 0.00      %15s                                     %15s', $day($date), $description, $fmt($amount, false), $fmt($balance, true));
            $balance = bcsub($balance, $amount, 2);
        }
        $out[]   = 'Printed 2025-07-14, 19:29                                           © 2009 First National Bank';

        return implode("\n", $out)."\n";
    }

    protected function upload(User $user, string $text, ?int $accountId = null, array $options = [], bool $reprocess = false): UploadOutcome
    {
        $path = tempnam(sys_get_temp_dir(), 'ext-stmt-');
        file_put_contents($path, $text);
        $importer = new StatementImporter($user, app(BankRegistry::class));

        try {
            return $importer->upload($path, 'statement.pdf', 'application/pdf', $accountId, null, $options, $reprocess, StatementFile::fromText($text));
        } finally {
            @unlink($path);
        }
    }

    protected function emailTransaction(User $user, Account $account, string $date, string $signedAmount, string $counterparty, ?string $card = null, ?string $externalId = null): TransactionGroup
    {
        $writer = new TransactionWriter($user, $user->userGroup, TransactionWriter::SOURCE_EMAIL);

        return $writer->store(new TransactionRow(
            account: $account,
            direction: str_starts_with($signedAmount, '-') ? 'out' : 'in',
            amount: ltrim($signedAmount, '-'),
            date: Carbon::parse($date.' 12:00:00', config('app.timezone')),
            description: $counterparty,
            counterpartyName: $counterparty,
            tags: ['fnb', 'email-import'],
            externalId: $externalId ?? 'fnb-'.substr(hash('sha256', $date.$signedAmount.$counterparty.microtime()), 0, 32),
            internalReference: null === $card ? null : 'card..'.$card,
        ));
    }
}
