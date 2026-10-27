<?php

/*
 * DevSeedTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Console;

use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Console\DevSeed;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(DevSeed::class)]
final class DevSeedTest extends FeatureTestCase
{
    public function testSeedsOnceAndIsIdempotent(): void
    {
        $this->artisan('ext:dev-seed')->assertSuccessful();
        $this->artisan('ext:dev-seed')->assertSuccessful();

        $user = User::query()->where('email', 'dev@example.com')->firstOrFail();
        self::assertSame(3, TransactionJournal::query()->where('user_id', $user->id)->whereHas('transactionType', static fn ($q) => $q->where('type', '!=', 'Opening balance'))->count());
        self::assertTrue((new AccountResolver($user->userGroup))->resolve('fnb', '100100')->resolved());
        self::assertTrue((new AccountResolver($user->userGroup))->resolve('fnb', '62012345678')->resolved());

        config(['app.env' => 'production']);
        $this->artisan('ext:dev-seed')->assertFailed();
    }
}
