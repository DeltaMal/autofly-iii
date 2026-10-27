<?php

/*
 * BankDefinition.php
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

use FireflyIII\Ext\Statements\StatementParser;

/**
 * A supported bank. Adding a bank = one folder under Banks/ + fixtures + tests, listed in config ext.banks.
 */
interface BankDefinition
{
    /** Stable id, e.g. 'fnb'; stored in ext_* tables. */
    public function id(): string;

    public function name(): string;

    /** ISO 3166-1 alpha-2, e.g. 'ZA'. */
    public function country(): string;

    /** @return list<string> sender addresses of alert emails (compared case-insensitively) */
    public function emailSenders(): array;

    /** @return list<object> email templates (FireflyIII\Ext\Mail\EmailTemplate), in the order they are tried */
    public function emailTemplates(): array;

    /** @return list<StatementParser> in the order they are tried */
    public function statementParsers(): array;
}
