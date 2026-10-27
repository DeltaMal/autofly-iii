<?php

/*
 * TemplateHelpers.php
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
use InvalidArgumentException;

/**
 * Ports of the Python importer's banks/base.py helpers. InvalidArgumentException stands in for ValueError.
 */
final class TemplateHelpers
{
    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * Only the first (topmost) Authentication-Results header counts: lower ones could come from the sender.
     *
     * @param list<string> $authResults
     */
    public static function senderAuthenticated(array $authResults, string $domain): bool
    {
        if ([] === $authResults) {
            return false;
        }
        $pattern = '/(*UCP)[@=.]'.preg_quote($domain, '/').'(?![\w.-])/iu';
        foreach (explode(';', $authResults[0]) as $clause) {
            $clause = mb_strtolower(Text::strip($clause), 'UTF-8');
            if ((str_starts_with($clause, 'dkim=pass') || str_starts_with($clause, 'spf=pass') || str_starts_with($clause, 'dmarc=pass')) && 1 === preg_match($pattern, $clause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop "," and spaces, then str(Decimal(...)): keeps the sign and the written decimals, drops leading
     * integer zeros (a single 0 stays before the point).
     */
    public static function parseAmount(string $text): string
    {
        $text = str_replace([',', ' '], '', $text);
        if (1 !== preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $match) || ('' === $match[2] && '' === ($match[3] ?? ''))) {
            throw new InvalidArgumentException(sprintf('Invalid amount "%s".', $text));
        }
        $int = ltrim($match[2], '0');
        $int = '' === $int ? '0' : $int;

        return ('-' === $match[1] ? '-' : '').$int.('' !== ($match[3] ?? '') ? '.'.$match[3] : '');
    }

    /**
     * The alert's "4Oct 12:59" in local time: the received year, else the year before; the first valid date
     * that is not later than received + 1 day.
     */
    public static function inferDateTime(int|string $day, string $month, string $time, Carbon $receivedAt, string $timezone): Carbon
    {
        $monthNumber = self::MONTHS[strtolower(substr($month, 0, 3))] ?? throw new InvalidArgumentException(sprintf('Unknown month "%s".', $month));
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})$/', $time, $parts) || (int) $parts[1] > 23 || (int) $parts[2] > 59) {
            throw new InvalidArgumentException(sprintf('Invalid time "%s".', $time));
        }
        $local   = $receivedAt->copy()->setTimezone($timezone);
        $latest  = $local->copy()->addDay();
        foreach ([$local->year, $local->year - 1] as $year) {
            if (!checkdate($monthNumber, (int) $day, $year)) {
                continue;
            }
            $candidate = Carbon::create($year, $monthNumber, (int) $day, (int) $parts[1], (int) $parts[2], 0, $timezone);
            if ($candidate->lte($latest)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf('No valid date for %s %s %s.', $day, $month, $time));
    }

    /** "<prefix>-" + first 32 hex of sha256(key). */
    public static function stableId(string $prefix, string $key): string
    {
        return $prefix.'-'.substr(hash('sha256', $key), 0, 32);
    }
}
