<?php

/*
 * AccountResolution.php
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

namespace FireflyIII\Ext\Banks;

/**
 * Result of AccountResolver::resolve(): an account id, or a typed failure. Never a guess.
 */
final readonly class AccountResolution
{
    public const string OK              = 'ok';
    public const string AMBIGUOUS       = 'ambiguous';
    public const string MISSING_MAPPING = 'missing_mapping';
    public const string NOT_FOUND       = 'not_found';

    private function __construct(public string $kind, public ?int $accountId, public ?string $problem, public string $via = '') {}

    public static function ok(int $accountId, string $via): self
    {
        return new self(self::OK, $accountId, null, $via);
    }

    public static function fail(string $kind, string $problem): self
    {
        return new self($kind, null, $problem);
    }

    public function resolved(): bool
    {
        return self::OK === $this->kind;
    }

    /** Python's not_found flag: no mapping and no matching account (the account is simply not tracked). */
    public function notFound(): bool
    {
        return self::NOT_FOUND === $this->kind;
    }
}
