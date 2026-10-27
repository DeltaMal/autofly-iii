<?php

/*
 * AccountIdentifier.php
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

namespace FireflyIII\Ext\Models;

use FireflyIII\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit mapping of a partial account number or card last-4 to a Firefly account.
 *
 * @property int    $id
 * @property int    $user_group_id
 * @property int    $account_id
 * @property string $bank
 * @property string $kind account_number|card
 * @property string $value digits
 */
final class AccountIdentifier extends Model
{
    use ScopedToUserGroup;

    public const string KIND_ACCOUNT_NUMBER = 'account_number';
    public const string KIND_CARD           = 'card';

    protected $table    = 'ext_account_identifiers';
    protected $fillable = ['user_group_id', 'account_id', 'bank', 'kind', 'value'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'user_group_id' => 'integer'];
    }
}
