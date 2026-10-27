<?php

/*
 * StatementLine.php
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
use FireflyIII\Models\TransactionJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an uploaded statement and its reconciliation outcome (ext_statement_lines).
 *
 * @property int         $id
 * @property int         $statement_id
 * @property int         $posting_index
 * @property Carbon      $date
 * @property string      $description
 * @property string      $amount
 * @property string      $fee
 * @property string      $balance
 * @property null|string $card_ref
 * @property null|string $counterparty
 * @property null|string $raw
 * @property bool        $is_fee_line
 * @property string      $line_hash
 * @property string      $status matched|missing|created|proposal|ignored
 * @property null|int    $transaction_journal_id
 * @property null|string $match_score
 * @property null|array  $proposal
 */
final class StatementLine extends Model
{
    use ScopedToUserGroup;

    public const string MATCHED  = 'matched';
    public const string MISSING  = 'missing';
    public const string CREATED  = 'created';
    public const string PROPOSAL = 'proposal';
    public const string IGNORED  = 'ignored';

    protected $table    = 'ext_statement_lines';
    protected $fillable = [
        'user_group_id', 'statement_id', 'posting_index', 'date', 'description', 'amount', 'fee', 'balance', 'card_ref', 'counterparty', 'raw', 'is_fee_line',
        'line_hash', 'status', 'transaction_journal_id', 'match_score', 'proposal',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(TransactionJournal::class, 'transaction_journal_id');
    }

    protected function casts(): array
    {
        return [
            'date' => 'date', 'is_fee_line' => 'boolean', 'proposal' => 'array', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'balance' => 'decimal:2',
            'transaction_journal_id' => 'integer', 'statement_id' => 'integer', 'posting_index' => 'integer', 'user_group_id' => 'integer',
        ];
    }
}
