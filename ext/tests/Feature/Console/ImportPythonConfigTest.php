<?php

/*
 * ImportPythonConfigTest.php
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

use FireflyIII\Ext\Console\ImportPythonConfig;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @internal
 */
#[CoversClass(ImportPythonConfig::class)]
final class ImportPythonConfigTest extends FeatureTestCase
{
    public function testImportsTheSampleAndRollsBackOnABadAccount(): void
    {
        $user    = $this->createUser('owner@example.com');
        $this->actingAs($user);
        $card    = $this->createAccount($user, 'FNB card', 'asset', '1101490000', 'ZAR');
        $file    = tempnam(sys_get_temp_dir(), 'ext-cfg-');
        $config  = [
            'timezone'  => 'Africa/Johannesburg',
            'mailboxes' => [[
                'id' => 'personal', 'provider' => 'gmail', 'enabled' => true, 'query' => '', 'lookback_days' => 30, 'banks' => ['fnb'],
                'labels' => ['imported' => 'Firefly/Imported', 'unmatched' => 'Firefly/Unmatched'],
                'token'  => ['refresh_token' => 'r-token', 'client_id' => 'desktop-id', 'client_secret' => 'desktop-secret', 'token_uri' => 'https://oauth2.googleapis.com/token', 'scopes' => ['https://www.googleapis.com/auth/gmail.modify']],
            ]],
            'banks'     => ['fnb' => ['accounts' => ['100100' => $card->id], 'account_match' => 'prefix', 'default_account_id' => null, 'tags' => ['fnb', 'email-import'], 'require_authenticated' => true]],
        ];
        file_put_contents($file, json_encode($config));

        $this->artisan('ext:import-python-config', ['file' => $file, '--user' => 'owner@example.com', '--dry-run' => true])->expectsOutputToContain('Mailbox personal')->assertSuccessful();
        self::assertSame(0, Mailbox::count());

        $this->artisan('ext:import-python-config', ['file' => $file, '--user' => 'owner@example.com'])->assertSuccessful();
        $mailbox = Mailbox::query()->firstOrFail();
        self::assertSame(Mailbox::SHADOW, $mailbox->mode);
        self::assertSame(['fnb'], $mailbox->banks);
        self::assertSame('r-token', $mailbox->oauth['refresh_token']);
        self::assertSame('desktop-id', $mailbox->oauth['client_id']);
        self::assertStringNotContainsString('r-token', (string) $mailbox->getRawOriginal('oauth'), 'tokens are encrypted at rest');
        self::assertSame(['fnb', 'email-import'], BankProfile::query()->firstOrFail()->tags);
        self::assertSame($card->id, AccountIdentifier::query()->where('value', '100100')->firstOrFail()->account_id);

        // a bad account id fails before anything is written
        Mailbox::query()->delete();
        BankProfile::query()->delete();
        AccountIdentifier::query()->delete();
        $config['banks']['fnb']['accounts']['300300'] = 99999;
        file_put_contents($file, json_encode($config));
        $this->artisan('ext:import-python-config', ['file' => $file, '--user' => 'owner@example.com'])->expectsOutputToContain('does not exist')->assertFailed();
        self::assertSame([0, 0, 0], [Mailbox::count(), BankProfile::count(), AccountIdentifier::count()]);

        // an existing mailbox name rolls the whole import back
        unset($config['banks']['fnb']['accounts']['300300']);
        file_put_contents($file, json_encode($config));
        Mailbox::create(['user_group_id' => $user->user_group_id, 'user_id' => $user->id, 'name' => 'personal', 'banks' => ['fnb']]);
        $this->artisan('ext:import-python-config', ['file' => $file, '--user' => 'owner@example.com'])->expectsOutputToContain('already exists')->assertFailed();
        self::assertSame([1, 0, 0], [Mailbox::count(), BankProfile::count(), AccountIdentifier::count()]);
        unlink($file);
    }
}
