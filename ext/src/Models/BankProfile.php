<?php

/*
 * BankProfile.php
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
use FireflyIII\Models\UserGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $user_group_id
 * @property string      $bank
 * @property string      $account_match prefix|suffix
 * @property null|int    $default_account_id
 * @property list<string> $tags
 * @property bool        $require_authenticated
 * @property bool        $enabled
 */
final class BankProfile extends Model
{
    use ScopedToUserGroup;

    protected $table    = 'ext_bank_profiles';
    protected $fillable = ['user_group_id', 'bank', 'account_match', 'default_account_id', 'tags', 'require_authenticated', 'enabled'];

    /**
     * The group's profile for $bank, or an unsaved default one (prefix match, tag = bank id, require authentication).
     */
    public static function forBank(UserGroup|int $group, string $bank): self
    {
        $groupId = $group instanceof UserGroup ? $group->id : $group;

        return self::query()->forGroup($groupId)->where('bank', $bank)->first()
            ?? new self(['user_group_id' => $groupId, 'bank' => $bank, 'account_match' => 'prefix', 'tags' => [$bank], 'require_authenticated' => true, 'enabled' => true]);
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }

    protected function casts(): array
    {
        return ['tags' => 'array', 'require_authenticated' => 'boolean', 'enabled' => 'boolean', 'default_account_id' => 'integer', 'user_group_id' => 'integer'];
    }
}
