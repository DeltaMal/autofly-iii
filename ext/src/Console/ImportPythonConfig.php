<?php

/*
 * ImportPythonConfig.php
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
use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Mail\Gmail\GmailOAuth;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ext:import-python-config: moves the Python importer's configuration (exported as JSON, ext/docs/03) into ext
 * tables for one user's current user group. Mailboxes arrive in shadow mode. Everything is validated before
 * anything is written, and the import is one DB transaction.
 */
final class ImportPythonConfig extends Command
{
    protected $signature   = 'ext:import-python-config {file : the exported JSON} {--user= : email of the Firefly user who owns the mailboxes} {--dry-run : print what would be created}';
    protected $description = 'Import the Python email importer configuration (mailboxes, tokens, banks, account mappings).';

    public function handle(BankRegistry $banks): int
    {
        $user = User::query()->where('email', (string) $this->option('user'))->first();
        if (null === $user) {
            $this->error('Give the owner with --user=<email> (an existing Firefly user).');

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        if (!is_file($file) || !is_readable($file)) {
            $this->error(sprintf('Cannot read %s.', $file));

            return self::FAILURE;
        }

        try {
            $config = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            $plan   = $this->plan(is_array($config) ? $config : [], $user, $banks);
        } catch (\JsonException $e) {
            $this->error('The file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($plan['banks'] as $bank) {
            $this->line(sprintf('Bank profile %s: match %s, default account %s, tags [%s], require authenticated %s', $bank['bank'], $bank['account_match'], $bank['default_account_id'] ?? '-', implode(', ', $bank['tags']), $bank['require_authenticated'] ? 'yes' : 'no'));
        }
        foreach ($plan['identifiers'] as $identifier) {
            $this->line(sprintf('Account mapping %s %s -> account #%d', $identifier['bank'], $identifier['value'], $identifier['account_id']));
        }
        foreach ($plan['mailboxes'] as $mailbox) {
            $this->line(sprintf('Mailbox %s (%s, banks %s, shadow mode%s)', $mailbox['name'], $mailbox['provider'], implode(', ', $mailbox['banks']), null === $mailbox['oauth'] ? ', no token' : ', with token'));
        }
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(static function () use ($plan, $user): void {
                foreach ($plan['banks'] as $bank) {
                    BankProfile::query()->updateOrCreate(['user_group_id' => $user->user_group_id, 'bank' => $bank['bank']], $bank + ['enabled' => true]);
                }
                foreach ($plan['identifiers'] as $identifier) {
                    AccountIdentifier::query()->updateOrCreate(
                        ['user_group_id' => $user->user_group_id, 'bank' => $identifier['bank'], 'kind' => AccountIdentifier::KIND_ACCOUNT_NUMBER, 'value' => $identifier['value']],
                        ['account_id' => $identifier['account_id']]
                    );
                }
                foreach ($plan['mailboxes'] as $mailbox) {
                    $existing = Mailbox::query()->forGroup($user->user_group_id)->where('name', $mailbox['name'])->first();
                    if (null !== $existing) {
                        throw new RuntimeException(sprintf('Mailbox "%s" already exists; delete it first.', $mailbox['name']));
                    }
                    Mailbox::create($mailbox + ['user_group_id' => $user->user_group_id, 'user_id' => $user->id, 'mode' => Mailbox::SHADOW]);
                }
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage().' Nothing was imported.');

            return self::FAILURE;
        }
        $this->info(sprintf('Imported %d bank profiles, %d account mappings and %d mailboxes (shadow mode). Delete %s now: it holds refresh tokens.', count($plan['banks']), count($plan['identifiers']), count($plan['mailboxes']), $file));

        return self::SUCCESS;
    }

    /**
     * Validates everything and returns the rows to write.
     */
    private function plan(array $config, User $user, BankRegistry $banks): array
    {
        $known  = (new AccountResolver($user->userGroup))->accounts();
        $check  = static function (mixed $id, string $what) use ($known): int {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new RuntimeException(sprintf('%s: "%s" is not an account id.', $what, (string) $id));
            }
            if (!array_key_exists((int) $id, $known)) {
                throw new RuntimeException(sprintf('%s: account #%d does not exist in this user group (or is not an asset or liability account). Nothing was imported.', $what, (int) $id));
            }

            return (int) $id;
        };
        $plan   = ['banks' => [], 'identifiers' => [], 'mailboxes' => []];
        foreach ((array) ($config['banks'] ?? []) as $bankId => $bank) {
            if (!$banks->has((string) $bankId)) {
                throw new RuntimeException(sprintf('Unknown bank "%s".', $bankId));
            }
            $match            = (string) ($bank['account_match'] ?? 'prefix');
            if (!in_array($match, ['prefix', 'suffix'], true)) {
                throw new RuntimeException(sprintf('banks.%s.account_match must be prefix or suffix.', $bankId));
            }
            $plan['banks'][]  = [
                'bank'                  => (string) $bankId,
                'account_match'         => $match,
                'default_account_id'    => null === ($bank['default_account_id'] ?? null) ? null : $check($bank['default_account_id'], sprintf('banks.%s.default_account_id', $bankId)),
                'tags'                  => array_values(array_map('strval', (array) ($bank['tags'] ?? []))),
                'require_authenticated' => (bool) ($bank['require_authenticated'] ?? true),
            ];
            foreach ((array) ($bank['accounts'] ?? []) as $partial => $accountId) {
                $digits = AccountResolver::digits((string) $partial);
                if ('' === $digits) {
                    throw new RuntimeException(sprintf('banks.%s.accounts: "%s" has no digits.', $bankId, $partial));
                }
                $plan['identifiers'][] = ['bank' => (string) $bankId, 'value' => $digits, 'account_id' => $check($accountId, sprintf('banks.%s.accounts.%s', $bankId, $partial))];
            }
        }
        foreach ((array) ($config['mailboxes'] ?? []) as $mailbox) {
            $name = (string) ($mailbox['id'] ?? '');
            if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                throw new RuntimeException(sprintf('Mailbox id "%s" is invalid.', $name));
            }
            if ('gmail' !== ($mailbox['provider'] ?? 'gmail')) {
                throw new RuntimeException(sprintf('Mailbox %s: only gmail is supported.', $name));
            }
            $bankIds = array_values(array_map('strval', (array) ($mailbox['banks'] ?? [])));
            if ([] === $bankIds || [] !== array_filter($bankIds, static fn (string $b): bool => !$banks->has($b))) {
                throw new RuntimeException(sprintf('Mailbox %s: banks must list known banks.', $name));
            }
            $token   = (array) ($mailbox['token'] ?? []);
            $plan['mailboxes'][] = [
                'name'            => $name,
                'provider'        => 'gmail',
                'enabled'         => (bool) ($mailbox['enabled'] ?? true),
                'query'           => (string) ($mailbox['query'] ?? ''),
                'lookback_days'   => max(1, (int) ($mailbox['lookback_days'] ?? 30)),
                'label_imported'  => (string) ($mailbox['labels']['imported'] ?? 'Firefly/Imported'),
                'label_unmatched' => (string) ($mailbox['labels']['unmatched'] ?? 'Firefly/Unmatched'),
                'banks'           => $bankIds,
                'oauth'           => '' === (string) ($token['refresh_token'] ?? '') ? null : [
                    'client_id'     => (string) ($token['client_id'] ?? ''),
                    'client_secret' => isset($token['client_secret']) ? (string) $token['client_secret'] : null,
                    'refresh_token' => (string) $token['refresh_token'],
                    'access_token'  => '',
                    'expires_at'    => Carbon::now()->getTimestamp(),
                    'scope'         => implode(' ', (array) ($token['scopes'] ?? [GmailOAuth::SCOPE])),
                ],
            ];
        }

        return $plan;
    }
}
