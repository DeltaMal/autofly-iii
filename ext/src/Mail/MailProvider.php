<?php

/*
 * MailProvider.php
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

namespace FireflyIII\Ext\Mail;

/**
 * A mailbox backend (Gmail today; IMAP is out of scope).
 */
interface MailProvider
{
    /** The mailbox's email address. */
    public function profile(): string;

    /** @return list<string> provider message ids */
    public function search(string $query): array;

    public function fetch(string $id): MailMessage;

    /** The complete RFC 822 message (kept for unmatched emails). */
    public function fetchRaw(string $id): string;

    /**
     * Labels to add are created when missing; labels to remove are skipped when missing.
     *
     * @param list<string> $ids
     * @param list<string> $add    label names
     * @param list<string> $remove label names
     */
    public function modifyLabels(array $ids, array $add, array $remove): void;
}
