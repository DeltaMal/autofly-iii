<?php

/*
 * 2026_10_28_000001_ext_create_auto_budget_limits_table.php
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
 * ext fork, follow-ups 3: budget limits that upstream's auto-budget creation made (ext/docs/01, "Auto-budgets").
 * Upstream does not mark its first limit of a new auto-budget as generated; this table remembers it, keyed to the
 * upstream budget_limits.id. Like ext_mail_message_transactions it is a link table, so it has no user_group_id:
 * it is always reached through the budget limit, which belongs to the user's budget.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ext_auto_budget_limits', static function (Blueprint $table): void {
            $table->unsignedInteger('budget_limit_id')->primary();
            $table->timestamps();
            $table->string('source', 16); // store | backfill
            $table->foreign('budget_limit_id')->references('id')->on('budget_limits')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_auto_budget_limits');
    }
};
