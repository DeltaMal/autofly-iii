<?php

/*
 * TransactionWriterTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Import;

use Carbon\Carbon;
use FireflyIII\Ext\Import\DuplicateExternalIdException;
use FireflyIII\Ext\Import\ExternalIdLookup;
use FireflyIII\Ext\Import\TransactionRow;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\TransactionJournal;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(TransactionWriter::class)]
#[CoversClass(ExternalIdLookup::class)]
final class TransactionWriterTest extends FeatureTestCase
{
    public function testTransferMatrixCurrencyTagsAndDedup(): void
    {
        $user    = $this->createUser();
        $this->actingAs($user);
        $cheque  = $this->createAccount($user, 'Cheque', 'asset', '100100', 'ZAR');
        $savings = $this->createAccount($user, 'Savings', 'asset', '300300', 'ZAR');
        $loan    = $this->createAccount($user, 'Home loan', 'loan', '040400', 'ZAR', ['liability_type' => 'loan', 'liability_direction' => 'credit', 'liability_amount' => '1000', 'liability_start_date' => today()]);
        $writer  = new TransactionWriter($user, $user->userGroup, TransactionWriter::SOURCE_EMAIL);
        $row     = static fn (array $args): TransactionRow => new TransactionRow(...array_merge([
            'account' => $cheque, 'direction' => 'out', 'amount' => '10.00', 'date' => Carbon::parse('2026-10-04 12:59', config('app.timezone')),
            'description' => 'Corner Deli', 'counterpartyName' => 'Corner Deli', 'tags' => ['fnb'], 'notes' => 'n', 'externalId' => null, 'internalReference' => 'card..7805',
        ], $args));
        $type    = static fn ($group): string => $group->transactionJournals()->first()->transactionType->type;

        self::assertSame('Withdrawal', $type($writer->store($row(['externalId' => 'fnb-a']))));
        self::assertSame('Deposit', $type($writer->store($row(['direction' => 'in', 'externalId' => 'fnb-b']))));
        self::assertSame('Transfer', $type($writer->store($row(['counterAccount' => $savings, 'externalId' => 'fnb-c']))));
        self::assertSame('Withdrawal', $type($writer->store($row(['counterAccount' => $loan, 'externalId' => 'fnb-d']))));
        self::assertSame('Deposit', $type($writer->store($row(['account' => $cheque, 'direction' => 'in', 'counterAccount' => $loan, 'externalId' => 'fnb-e']))));

        $journal = TransactionJournal::with('tags', 'transactionCurrency')->whereHas('transactionJournalMeta', static fn ($q) => $q->where('name', 'internal_reference'))->first();
        self::assertSame('ZAR', $journal->transactionCurrency->code);
        self::assertSame(['Email import '.today(config('app.timezone'))->format('Y-m-d'), 'fnb'], $journal->tags->pluck('tag')->sort()->values()->all());
        self::assertSame('2026-10-04 12:59', $journal->date->format('Y-m-d H:i'));

        // existing id (also once soft-deleted) is refused
        $lookup  = new ExternalIdLookup($user->userGroup);
        $found   = $lookup->find('fnb-a');
        self::assertNotNull($found);
        TransactionJournal::find($found['journal_id'])->delete();
        self::assertTrue($lookup->find('fnb-a')['deleted']);
        $this->expectException(DuplicateExternalIdException::class);
        $writer->store($row(['externalId' => 'fnb-a', 'amount' => '11.00']));
    }

    public function testLookupIsScopedToTheUserGroup(): void
    {
        $user   = $this->createUser();
        $this->actingAs($user);
        $cheque = $this->createAccount($user, 'Cheque');
        (new TransactionWriter($user, $user->userGroup, TransactionWriter::SOURCE_EMAIL))->store(new TransactionRow($cheque, 'out', '1.00', Carbon::now(), 'x', 'x', externalId: 'fnb-shared'));
        $other  = $this->createUser('other@example.com');

        self::assertTrue((new ExternalIdLookup($user->userGroup))->exists('fnb-shared'));
        self::assertFalse((new ExternalIdLookup($other->userGroup))->exists('fnb-shared'));
        self::assertSame(['fnb-shared'], array_keys((new ExternalIdLookup($user->userGroup))->findMany(['fnb-shared', 'fnb-other'])));
    }
}
