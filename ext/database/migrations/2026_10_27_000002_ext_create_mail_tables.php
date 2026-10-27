<?php

/*
 * 2026_10_27_000002_ext_create_mail_tables.php
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
 * ext fork, Phase 3: mailboxes, fetched alert emails, parsed transactions and their links (ext/docs/00, 03).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_mailboxes', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedInteger('user_id');
            $table->string('name', 100);
            $table->string('provider', 16)->default('gmail');
            $table->string('email_address', 255)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('mode', 8)->default('shadow'); // live | shadow
            $table->text('query')->nullable();
            $table->unsignedSmallInteger('lookback_days')->default(30);
            $table->string('label_imported', 100)->default('Firefly/Imported');
            $table->string('label_unmatched', 100)->default('Firefly/Unmatched');
            $table->json('banks')->nullable();
            $table->text('oauth')->nullable(); // encrypted
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unique(['user_group_id', 'name']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('ext_mail_messages', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedBigInteger('mailbox_id');
            $table->string('provider_message_id', 255);
            $table->timestamp('received_at')->nullable();
            $table->string('sender', 255)->nullable();
            $table->string('subject', 1024)->nullable();
            $table->string('outcome', 16); // transactions | ignored | unmatched
            $table->string('status', 24);
            $table->boolean('label_synced')->default(false);
            $table->text('unmatched_text')->nullable();
            $table->longText('bodies')->nullable(); // encrypted
            $table->longText('raw')->nullable(); // encrypted, unmatched only
            $table->text('error')->nullable();
            $table->unique(['mailbox_id', 'provider_message_id']);
            $table->index(['user_group_id', 'status']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('ext_mailboxes')->cascadeOnDelete();
        });

        Schema::create('ext_mail_transactions', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_group_id');
            $table->string('external_id', 64);
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('account_id')->nullable();
            $table->unsignedInteger('transaction_group_id')->nullable();
            $table->text('error')->nullable();
            $table->json('payload');
            $table->unique(['user_group_id', 'external_id']);
            $table->index(['user_group_id', 'status']);
            $table->foreign('user_group_id')->references('id')->on('user_groups')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('transaction_group_id')->references('id')->on('transaction_groups')->nullOnDelete();
        });

        Schema::create('ext_mail_message_transactions', static function (Blueprint $table): void {
            $table->unsignedBigInteger('mail_message_id');
            $table->unsignedBigInteger('mail_transaction_id');
            $table->primary(['mail_message_id', 'mail_transaction_id']);
            $table->foreign('mail_message_id')->references('id')->on('ext_mail_messages')->cascadeOnDelete();
            $table->foreign('mail_transaction_id')->references('id')->on('ext_mail_transactions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_mail_message_transactions');
        Schema::dropIfExists('ext_mail_transactions');
        Schema::dropIfExists('ext_mail_messages');
        Schema::dropIfExists('ext_mailboxes');
    }
};
