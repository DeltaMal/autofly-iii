<?php

/*
 * Mailbox.php
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

use Carbon\Carbon;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A connected mailbox (ext_mailboxes). oauth is encrypted: {client_id, client_secret|null, refresh_token,
 * access_token, expires_at, scope}.
 *
 * @property int         $id
 * @property int         $user_group_id
 * @property int         $user_id
 * @property string      $name
 * @property string      $provider
 * @property null|string $email_address
 * @property bool        $enabled
 * @property string      $mode live|shadow
 * @property null|string $query
 * @property int         $lookback_days
 * @property string      $label_imported
 * @property string      $label_unmatched
 * @property list<string> $banks
 * @property null|array  $oauth
 * @property null|Carbon $last_polled_at
 * @property null|string $last_error
 */
final class Mailbox extends Model
{
    use ScopedToUserGroup;

    public const string LIVE   = 'live';
    public const string SHADOW = 'shadow';

    public const string RECONNECT_REQUIRED = 'reconnect required';

    protected $table    = 'ext_mailboxes';
    protected $fillable = [
        'user_group_id', 'user_id', 'name', 'provider', 'email_address', 'enabled', 'mode', 'query', 'lookback_days', 'label_imported', 'label_unmatched',
        'banks', 'oauth', 'last_polled_at', 'last_error',
    ];
    protected $hidden   = ['oauth'];
    protected $attributes = [
        'provider'        => 'gmail',
        'enabled'         => true,
        'mode'            => self::SHADOW,
        'lookback_days'   => 30,
        'label_imported'  => 'Firefly/Imported',
        'label_unmatched' => 'Firefly/Unmatched',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    public function isShadow(): bool
    {
        return self::SHADOW === $this->mode;
    }

    public function isConnected(): bool
    {
        return '' !== (string) ($this->oauth['refresh_token'] ?? '');
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'banks' => 'array', 'oauth' => 'encrypted:array', 'last_polled_at' => 'datetime', 'lookback_days' => 'integer', 'user_group_id' => 'integer', 'user_id' => 'integer'];
    }
}
