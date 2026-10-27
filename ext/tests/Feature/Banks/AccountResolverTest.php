<?php

/*
 * AccountResolverTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Banks;

use FireflyIII\Ext\Banks\AccountResolution;
use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * accounts.py behaviour (ext/docs/06 section 4).
 *
 * @internal
 */
#[CoversClass(AccountResolver::class)]
#[CoversClass(AccountResolution::class)]
final class AccountResolverTest extends FeatureTestCase
{
    public function testResolutionOrder(): void
    {
        $user      = $this->createUser();
        $this->actingAs($user);
        $cheque    = $this->createAccount($user, 'Cheque', 'asset', '1001 0012 345');
        $savings   = $this->createAccount($user, 'Savings', 'asset', '3003001234');
        $loan      = $this->createAccount($user, 'Home loan', 'loan', '0404009999', extra: ['liability_type' => 'loan', 'liability_direction' => 'credit', 'liability_amount' => '1000', 'liability_start_date' => today()]);
        $inactive  = $this->createAccount($user, 'Old', 'asset', '5005001111', extra: ['active' => false]);
        $this->createAccount($user, 'Twin A', 'asset', '7007001111');
        $this->createAccount($user, 'Twin B', 'asset', '7007002222');
        $expense   = $this->createAccount($user, 'Shop', 'expense', '9009001111');
        $resolver  = static fn () => new AccountResolver($user->userGroup);

        // 2. number match, spaces ignored; liabilities count; inactive and expense accounts do not
        self::assertSame($cheque->id, $resolver()->resolve('fnb', '100100')->accountId);
        self::assertSame('number', $resolver()->resolve('fnb', '100100')->via);
        self::assertSame($loan->id, $resolver()->resolve('fnb', '040400')->accountId);
        self::assertSame(AccountResolution::NOT_FOUND, $resolver()->resolve('fnb', '500500')->kind);
        self::assertSame(AccountResolution::NOT_FOUND, $resolver()->resolve('fnb', '900900')->kind);
        self::assertTrue($resolver()->resolve('fnb', '900900')->notFound());
        self::assertSame(AccountResolution::AMBIGUOUS, $resolver()->resolve('fnb', '700700')->kind);
        // longer than any account number
        self::assertSame(AccountResolution::NOT_FOUND, $resolver()->resolve('fnb', '10010012345999')->kind);

        // suffix mode
        BankProfile::create(['user_group_id' => $user->user_group_id, 'bank' => 'fnb', 'account_match' => 'suffix', 'tags' => [], 'require_authenticated' => true, 'enabled' => true, 'default_account_id' => $savings->id]);
        self::assertSame($savings->id, $resolver()->resolve('fnb', '1234')->accountId);
        self::assertStringContainsString('ends with', (string) $resolver()->resolve('fnb', '8888', false)->problem);

        // 3. default account only when allowed
        self::assertSame($savings->id, $resolver()->resolve('fnb', '8888')->accountId);
        self::assertSame('default', $resolver()->resolve('fnb', '8888')->via);
        self::assertFalse($resolver()->resolve('fnb', '8888', false)->resolved());

        // 1. explicit mapping wins over an ambiguous number; a mapping to a missing account fails, never guesses
        AccountIdentifier::create(['user_group_id' => $user->user_group_id, 'account_id' => $cheque->id, 'bank' => 'fnb', 'kind' => 'account_number', 'value' => '1111']);
        self::assertSame($cheque->id, $resolver()->resolve('fnb', '1111')->accountId);
        self::assertSame('mapping', $resolver()->resolve('fnb', '1111')->via);
        AccountIdentifier::create(['user_group_id' => $user->user_group_id, 'account_id' => $expense->id, 'bank' => 'fnb', 'kind' => 'account_number', 'value' => '424242']);
        self::assertSame(AccountResolution::MISSING_MAPPING, $resolver()->resolve('fnb', '424242')->kind);

        // cards: explicit only
        AccountIdentifier::create(['user_group_id' => $user->user_group_id, 'account_id' => $cheque->id, 'bank' => 'fnb', 'kind' => 'card', 'value' => '7805']);
        self::assertSame($cheque->id, $resolver()->resolve('fnb', '7805', false, 'card')->accountId);
        self::assertSame(AccountResolution::NOT_FOUND, $resolver()->resolve('fnb', '1234', false, 'card')->kind);

        // other user groups are invisible
        $other     = $this->createUser('other@example.com');
        self::assertFalse((new AccountResolver($other->userGroup))->resolve('fnb', '100100')->resolved());
        unset($inactive);
    }
}
