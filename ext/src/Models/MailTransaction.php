<?php

/*
 * MailTransaction.php
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

use FireflyIII\Ext\Mail\ParsedTransaction;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A transaction parsed from an alert email (ext_mail_transactions); one row per (group, external id):
 * the first sighting wins.
 *
 * @property int         $id
 * @property int         $user_group_id
 * @property string      $external_id
 * @property string      $status pending|unmapped|imported|failed|shadow_exists|shadow_would_create
 * @property int         $attempts
 * @property null|int    $account_id
 * @property null|int    $transaction_group_id
 * @property null|string $error
 * @property array       $payload
 */
final class MailTransaction extends Model
{
    use ScopedToUserGroup;

    public const string PENDING             = 'pending';
    public const string UNMAPPED            = 'unmapped';
    public const string IMPORTED            = 'imported';
    public const string FAILED              = 'failed';
    public const string SHADOW_EXISTS       = 'shadow_exists';
    public const string SHADOW_WOULD_CREATE = 'shadow_would_create';
    public const int MAX_ATTEMPTS           = 3;

    protected $table    = 'ext_mail_transactions';
    protected $fillable = ['user_group_id', 'external_id', 'status', 'attempts', 'account_id', 'transaction_group_id', 'error', 'payload'];

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(MailMessage::class, 'ext_mail_message_transactions', 'mail_transaction_id', 'mail_message_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(TransactionGroup::class);
    }

    public function parsed(): ParsedTransaction
    {
        return ParsedTransaction::fromArray($this->payload);
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'account_id' => 'integer', 'transaction_group_id' => 'integer', 'user_group_id' => 'integer'];
    }
}
