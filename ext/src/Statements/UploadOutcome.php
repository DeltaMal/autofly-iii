<?php

/*
 * UploadOutcome.php
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

namespace FireflyIII\Ext\Statements;

use FireflyIII\Ext\Models\Statement;

final readonly class UploadOutcome
{
    public const string REVIEW        = 'review';
    public const string COMMITTED     = 'committed';
    public const string DUPLICATE     = 'duplicate';
    public const string NEEDS_ACCOUNT = 'needs_account';

    private function __construct(public string $kind, public ?Statement $statement = null, public ?string $notice = null) {}

    public static function review(Statement $statement, ?string $notice = null): self
    {
        return new self(self::REVIEW, $statement, $notice);
    }

    public static function committed(Statement $statement): self
    {
        return new self(self::COMMITTED, $statement);
    }

    public static function duplicate(): self
    {
        return new self(self::DUPLICATE, null, 'This exact file was already uploaded for this account. Tick "re-process" to upload it again.');
    }

    public static function needsAccount(string $problem): self
    {
        return new self(self::NEEDS_ACCOUNT, null, $problem);
    }
}
