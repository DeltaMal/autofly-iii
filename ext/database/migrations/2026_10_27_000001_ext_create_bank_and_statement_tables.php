<?php

/*
 * 2026_10_27_000001_ext_create_bank_and_statement_tables.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ext fork, Phase 2: bank profiles, account identifiers, statements and statement lines (ext/docs/00 data model).
 * Only new ext_* tables; upstream tables are never altered.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_bank_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->string('bank', 32);
            $table->string('account_match', 8)->default('prefix');
            $table->unsignedInteger('default_account_id')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('require_authenticated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->unique(['user_group_id', 'bank']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('default_account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::create('ext_account_identifiers', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedInteger('account_id');
            $table->string('bank', 32);
            $table->string('kind', 16); // account_number | card
            $table->string('value', 64); // digits
            $table->unique(['user_group_id', 'bank', 'kind', 'value'], 'ext_account_identifiers_unique');
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });

        Schema::create('ext_statements', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('account_id');
            $table->string('bank', 32);
            $table->string('parser', 64);
            $table->string('file_name', 255);
            $table->string('file_sha256', 64);
            $table->string('file_path', 255)->nullable();
            $table->date('statement_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 32, 12);
            $table->decimal('closing_balance', 32, 12);
            $table->decimal('available_balance', 32, 12)->nullable();
            $table->boolean('chain_valid')->default(false);
            $table->string('status', 16)->default('parsed'); // parsed | committed
            $table->json('options')->nullable();
            $table->json('summary')->nullable();
            $table->index(['account_id', 'file_sha256']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });

        Schema::create('ext_statement_lines', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedBigInteger('statement_id');
            $table->unsignedInteger('posting_index');
            $table->date('date');
            $table->string('description', 1024);
            $table->decimal('amount', 32, 12);
            $table->decimal('fee', 32, 12)->default(0);
            $table->decimal('balance', 32, 12);
            $table->string('card_ref', 8)->nullable();
            $table->string('counterparty', 1024)->nullable();
            $table->text('raw')->nullable();
            $table->boolean('is_fee_line')->default(false);
            $table->string('line_hash', 64);
            $table->string('status', 16); // matched | missing | created | proposal | ignored
            $table->unsignedInteger('transaction_journal_id')->nullable();
            $table->decimal('match_score', 16, 6)->nullable();
            $table->json('proposal')->nullable();
            $table->index('line_hash');
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('statement_id')->references('id')->on('ext_statements')->cascadeOnDelete();
            $table->foreign('transaction_journal_id')->references('id')->on('transaction_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_statement_lines');
        Schema::dropIfExists('ext_statements');
        Schema::dropIfExists('ext_account_identifiers');
        Schema::dropIfExists('ext_bank_profiles');
    }
};
