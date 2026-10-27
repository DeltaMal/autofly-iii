<?php

/*
 * MailMessage.php
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A fetched alert email (ext_mail_messages).
 *
 * @property int         $id
 * @property int         $user_group_id
 * @property int         $mailbox_id
 * @property string      $provider_message_id
 * @property null|Carbon $received_at
 * @property null|string $sender
 * @property null|string $subject
 * @property string      $outcome transactions|ignored|unmatched
 * @property string      $status
 * @property bool        $label_synced
 * @property null|string $unmatched_text
 * @property null|array  $bodies
 * @property null|string $raw
 * @property null|string $error
 */
final class MailMessage extends Model
{
    use ScopedToUserGroup;

    public const string UNMATCHED           = 'unmatched';
    public const string IGNORED             = 'ignored';
    public const string IMPORTED            = 'imported';
    public const string FAILED              = 'failed';
    public const string PENDING             = 'pending';
    public const string SHADOW_EXISTS       = 'shadow_exists';
    public const string SHADOW_WOULD_CREATE = 'shadow_would_create';

    protected $table    = 'ext_mail_messages';
    protected $fillable = ['user_group_id', 'mailbox_id', 'provider_message_id', 'received_at', 'sender', 'subject', 'outcome', 'status', 'label_synced', 'unmatched_text', 'bodies', 'raw', 'error'];
    protected $hidden   = ['raw', 'bodies'];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(MailTransaction::class, 'ext_mail_message_transactions', 'mail_message_id', 'mail_transaction_id');
    }

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'label_synced' => 'boolean', 'bodies' => 'encrypted:array', 'raw' => 'encrypted', 'mailbox_id' => 'integer', 'user_group_id' => 'integer'];
    }
}
