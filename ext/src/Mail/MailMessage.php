<?php

/*
 * MailMessage.php
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

use Carbon\Carbon;

/**
 * One fetched email, already decoded: bodies are text (HTML-derived first, then plain), duplicates dropped.
 */
final readonly class MailMessage
{
    /**
     * @param list<string> $authResults Authentication-Results headers, in header order (topmost first)
     * @param list<string> $bodies
     */
    public function __construct(
        public string $mailboxId,
        public string $messageId,
        public Carbon $receivedAt,
        public string $sender,
        public string $subject,
        public array $authResults,
        public array $bodies,
    ) {}

    /**
     * Bodies from raw parts, like Python's extract_bodies(): HTML part first (as text), then text/plain.
     *
     * @return list<string>
     */
    public static function bodiesFromParts(?string $html, ?string $plain): array
    {
        $bodies = [];
        foreach ([null === $html ? null : Text::htmlToText($html), null === $plain ? null : Text::normaliseText($plain)] as $body) {
            if (null !== $body && '' !== $body && !in_array($body, $bodies, true)) {
                $bodies[] = $body;
            }
        }

        return $bodies;
    }

    /** "Name <a@b>" -> "a@b", lowercased. */
    public static function address(string $from): string
    {
        if (1 === preg_match('/<([^<>]+)>/', $from, $match)) {
            $from = $match[1];
        }

        return strtolower(trim($from, " \t\"'"));
    }
}
