<?php

/*
 * Statement.php
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
use FireflyIII\Models\Account;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded statement (ext_statements).
 *
 * @property int         $id
 * @property int         $user_group_id
 * @property int         $user_id
 * @property int         $account_id
 * @property string      $bank
 * @property string      $parser
 * @property string      $file_name
 * @property string      $file_sha256
 * @property null|string $file_path
 * @property Carbon      $statement_date
 * @property Carbon      $period_start
 * @property Carbon      $period_end
 * @property string      $opening_balance
 * @property string      $closing_balance
 * @property null|string $available_balance
 * @property bool        $chain_valid
 * @property string      $status parsed|committed
 * @property array       $options
 * @property null|array  $summary
 */
final class Statement extends Model
{
    use ScopedToUserGroup;

    public const string STATUS_PARSED    = 'parsed';
    public const string STATUS_COMMITTED = 'committed';

    protected $table    = 'ext_statements';
    protected $fillable = [
        'user_group_id', 'user_id', 'account_id', 'bank', 'parser', 'file_name', 'file_sha256', 'file_path', 'statement_date', 'period_start',
        'period_end', 'opening_balance', 'closing_balance', 'available_balance', 'chain_valid', 'status', 'options', 'summary',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class)->orderBy('posting_index');
    }

    protected function casts(): array
    {
        return [
            'statement_date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'chain_valid' => 'boolean', 'options' => 'array', 'summary' => 'array',
            'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2', 'available_balance' => 'decimal:2', 'account_id' => 'integer', 'user_group_id' => 'integer',
        ];
    }
}
