<?php

/*
 * AccountResolver.php
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

namespace FireflyIII\Ext\Banks;

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Models\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Finds the Firefly account for a bank's partial account number (or card last-4). An exact port of the Python
 * importer's accounts.py (ext/docs/06 section 4):
 *
 * 1. an explicit identifier in ext_account_identifiers (it must point at an existing asset/liability account);
 * 2. exactly one active asset or liability account whose digits-only account number starts with (suffix mode:
 *    ends with) the partial and is at least as long; several -> ambiguous, never a guess;
 * 3. the bank profile's default account, only when the caller allows it;
 * 4. otherwise not_found.
 */
final class AccountResolver
{
    /** @var null|array<int, array{active: bool, type: string, number: string, name: string}> */
    private ?array $accounts = null;

    /** @var array<string, BankProfile> */
    private array $profiles  = [];

    public function __construct(private readonly UserGroup $userGroup) {}

    public function resolve(string $bank, string $partial, bool $useDefault = true, string $kind = AccountIdentifier::KIND_ACCOUNT_NUMBER): AccountResolution
    {
        $partial  = self::digits($partial);
        $profile  = $this->profile($bank);
        $accounts = $this->accounts();

        // 1. explicit mapping
        if ('' !== $partial) {
            $mapped = AccountIdentifier::query()->forGroup($this->userGroup)->where('bank', $bank)->where('kind', $kind)->where('value', $partial)->first();
            if (null !== $mapped) {
                if (!array_key_exists($mapped->account_id, $accounts)) {
                    return AccountResolution::fail(AccountResolution::MISSING_MAPPING, sprintf('%s %s is mapped to account #%d, which does not exist (or is not an asset or liability account).', strtoupper($bank), $partial, $mapped->account_id));
                }

                return AccountResolution::ok($mapped->account_id, 'mapping');
            }
        }

        // 2. number match (account numbers only; card numbers are never stored on Firefly accounts)
        $suffix   = 'suffix' === $profile->account_match;
        if ('' !== $partial && AccountIdentifier::KIND_ACCOUNT_NUMBER === $kind) {
            $candidates = [];
            foreach ($accounts as $id => $account) {
                if (!$account['active'] || '' === $account['number'] || strlen($account['number']) < strlen($partial)) {
                    continue;
                }
                if ($suffix ? str_ends_with($account['number'], $partial) : str_starts_with($account['number'], $partial)) {
                    $candidates[] = $id;
                }
            }
            if (1 === count($candidates)) {
                return AccountResolution::ok($candidates[0], 'number');
            }
            if (count($candidates) > 1) {
                sort($candidates);

                return AccountResolution::fail(AccountResolution::AMBIGUOUS, sprintf('%s %s matches several accounts (#%s); add an explicit mapping.', strtoupper($bank), $partial, implode(', #', $candidates)));
            }
        }

        // 3. default account
        if ($useDefault && null !== $profile->default_account_id) {
            if (!array_key_exists($profile->default_account_id, $accounts)) {
                return AccountResolution::fail(AccountResolution::MISSING_MAPPING, sprintf('The default %s account #%d does not exist.', strtoupper($bank), $profile->default_account_id));
            }

            return AccountResolution::ok($profile->default_account_id, 'default');
        }

        // 4. not found
        return AccountResolution::fail(
            AccountResolution::NOT_FOUND,
            AccountIdentifier::KIND_CARD === $kind
                ? sprintf('No %s card ..%s is mapped to an account.', strtoupper($bank), $partial)
                : sprintf('No active asset or liability account whose number %s with %s.', $suffix ? 'ends' : 'starts', $partial)
        );
    }

    /**
     * Active asset and liability accounts of the group, by id, with digits-only account numbers.
     *
     * @return array<int, array{active: bool, type: string, number: string, name: string}>
     */
    public function accounts(): array
    {
        if (null !== $this->accounts) {
            return $this->accounts;
        }
        $types          = [AccountTypeEnum::ASSET->value, ...config('firefly.valid_liabilities')];
        // the query builder, not the Account model: the model decodes account_meta data on its own
        $rows           = DB::table('accounts')
            ->where('accounts.user_group_id', $this->userGroup->id)
            ->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->leftJoin('account_meta', static function ($join): void {
                $join->on('account_meta.account_id', '=', 'accounts.id')->where('account_meta.name', '=', 'account_number');
            })
            ->whereIn('account_types.type', $types)
            ->whereNull('accounts.deleted_at')
            ->get(['accounts.id', 'accounts.name', 'accounts.active', 'account_types.type', 'account_meta.data as account_number']);
        $this->accounts = [];
        foreach ($rows as $row) {
            $number                       = null === $row->account_number ? '' : (string) json_decode((string) $row->account_number, true);
            $this->accounts[(int) $row->id] = ['active' => (bool) $row->active, 'type' => (string) $row->type, 'number' => self::digits($number), 'name' => (string) $row->name];
        }

        return $this->accounts;
    }

    public function profile(string $bank): BankProfile
    {
        return $this->profiles[$bank] ??= BankProfile::forBank($this->userGroup, $bank);
    }

    public static function digits(string $text): string
    {
        return (string) preg_replace('/\D+/', '', $text);
    }
}
