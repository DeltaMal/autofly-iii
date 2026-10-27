<?php

/*
 * DevSeed.php
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

namespace FireflyIII\Ext\Console;

use Carbon\Carbon;
use FireflyIII\Ext\Import\TransactionRow;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the ext dev stack (ext/docker/compose.dev.yml) for manual testing: an owner user, asset accounts whose
 * numbers match the anonymised fixtures (62012345678, and partials such as 100100 from the email golden file)
 * and a few email-style transactions. Refuses to run in production.
 */
final class DevSeed extends Command
{
    protected $signature   = 'ext:dev-seed {--email=dev@example.com} {--password=dev-password-only} {--force : run even when APP_ENV is production}';
    protected $description = 'Seed the ext dev stack with a user, FNB-numbered accounts and email-style transactions.';

    public function handle(): int
    {
        if ('production' === config('app.env') && !$this->option('force')) {
            $this->error('Refusing to seed a production instance (use --force on a throw-away database only).');

            return self::FAILURE;
        }
        $email    = (string) $this->option('email');
        $user     = User::query()->where('email', $email)->first() ?? $this->createOwner($email, (string) $this->option('password'));
        /** @var AccountRepositoryInterface $accounts */
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser($user);

        $cheque   = $this->account($accounts, 'FNB Cheque (dev)', '62012345678', '10019.49');
        $this->account($accounts, 'FNB card account (dev)', '1001001234', '0');
        $this->account($accounts, 'FNB Smart Account (dev)', '3003005678', '0');

        $writer   = new TransactionWriter($user, $user->userGroup, TransactionWriter::SOURCE_EMAIL);
        $samples  = [
            ['2025-07-09 18:02', 'out', '74.77', 'PnP God Fehulo', '1234', 'fnb-dev00000000000000000000000001'],
            ['2025-07-12 08:15', 'in', '105779.24', 'Warev Gojiyeb', null, 'fnb-dev00000000000000000000000002'],
            ['2025-07-13 12:59', 'out', '767.98', 'Corner Deli', '1234', 'fnb-dev00000000000000000000000003'],
        ];
        $created  = 0;
        foreach ($samples as [$date, $direction, $amount, $counterparty, $card, $externalId]) {
            try {
                $writer->store(new TransactionRow($cheque, $direction, $amount, Carbon::parse($date, config('app.timezone')), $counterparty, $counterparty, tags: ['fnb', 'email-import'], externalId: $externalId, internalReference: null === $card ? null : 'card..'.$card));
                ++$created;
            } catch (\FireflyIII\Ext\Import\DuplicateExternalIdException) {
                // already seeded
            }
        }
        $this->info(sprintf('Seeded %s: accounts ready, %d new email-style transactions.', $email, $created));

        return self::SUCCESS;
    }

    private function createOwner(string $email, string $password): User
    {
        $group = UserGroup::create(['title' => $email]);
        $user  = User::create(['email' => $email, 'password' => Hash::make($password), 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => UserRole::query()->where('title', 'owner')->firstOrFail()->id]);
        app(UserRepositoryInterface::class)->attachRole($user, 'owner');
        $this->info(sprintf('Created owner %s (password: %s).', $email, $password));

        return $user;
    }

    private function account(AccountRepositoryInterface $accounts, string $name, string $number, string $openingBalance): Account
    {
        $existing = $accounts->findByName($name, ['Asset account']);
        if (null !== $existing) {
            return $existing;
        }
        $data = ['name' => $name, 'account_type_name' => 'asset', 'account_role' => 'defaultAsset', 'currency_code' => 'ZAR', 'iban' => null, 'account_number' => $number, 'active' => true, 'virtual_balance' => ''];
        if ('0' !== $openingBalance) {
            $data += ['opening_balance' => $openingBalance, 'opening_balance_date' => Carbon::parse('2025-05-20', config('app.timezone'))];
        }

        return $accounts->store($data);
    }
}
