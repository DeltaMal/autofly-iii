<?php

/*
 * FeatureTestCase.php
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

namespace FireflyIII\Ext\Tests\Feature;

use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Boots the full Firefly III application on an in-memory SQLite database
 * with upstream's and ext's migrations, plus upstream's seeders.
 */
abstract class FeatureTestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function createUser(string $email = 'ext@example.com'): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => bcrypt('password'), 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }

    /**
     * An account through upstream's repository (so meta data like the currency and account number are set).
     */
    protected function createAccount(User $user, string $name, string $type = 'asset', ?string $accountNumber = null, string $currency = 'EUR', array $extra = []): Account
    {
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);

        return $repository->store(array_merge([
            'name'              => $name,
            'account_type_name' => $type,
            'account_role'      => 'asset' === $type ? 'defaultAsset' : null,
            'currency_code'     => $currency,
            'iban'              => null,
            'account_number'    => $accountNumber,
            'active'            => true,
            'virtual_balance'   => '',
        ], $extra));
    }
}
