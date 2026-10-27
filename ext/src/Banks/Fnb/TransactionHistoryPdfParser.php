<?php

/*
 * TransactionHistoryPdfParser.php
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

namespace FireflyIII\Ext\Banks\Fnb;

use Carbon\Carbon;
use FireflyIII\Ext\Statements\ParsedStatement;
use FireflyIII\Ext\Statements\StatementException;
use FireflyIII\Ext\Statements\StatementFile;
use FireflyIII\Ext\Statements\StatementLine;
use FireflyIII\Ext\Statements\StatementParser;

/**
 * FNB "Transaction History" PDF (online/app banking: Transaction History -> Download/Print), read from
 * pdftotext -layout. A port of parse() in ext/scripts/anonymise_fnb_history.py; see ext/docs/02.
 *
 * Rows are matched from the right with one regex (column positions shift from row to row), indented lines
 * continue a row, and blank/footer/header lines end it. Rows are in posting order (newest first) and dates
 * are not monotonic, so nothing is ever sorted.
 */
final class TransactionHistoryPdfParser implements StatementParser
{
    public const string ID = 'fnb.transaction_history_pdf';

    private const string ROW    = '/^(?<date>\d{2} [A-Z][a-z]{2} \d{4})(?<g1> +)(?<desc>\S.*?)(?<g2> +)(?<fee>-?\d[\d,]*\.\d{2})(?<g3> +)(?<amount>-?\d[\d,]*\.\d{2} (?:DR|CR))(?<g4> +)(?<balance>-?\d[\d,]*\.\d{2} (?:CR|DR))\s*$/u';
    private const string CARD   = '/\d{6}\*{6}\d{4}/u';
    private const string FOOTER = '/^(Printed |info@|Address:|Tel:)/u';
    private const string HEADER = '/^\f?Date\s+Description\s+Service Fee\s+Amount\s+Balance/u';
    private const string FIELD  = '/^(?<label>Selected Account|Date|Available Balance|Current Balance):(?<gap> +)(?<value>.+?)\s*$/u';
    private const array MONTHS  = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    /** Keywords whose following text is the counterparty, longest first. */
    private const array COUNTERPARTY_KEYWORDS = ['FNB APP TRANSFER FROM', 'FNB APP PAYMENT FROM', 'INTERNET TRF FROM', 'FNB APP TRANSFER', 'FNB APP PAYMENT', 'INTERNET TRF'];

    public function id(): string
    {
        return self::ID;
    }

    public function supports(StatementFile $file): bool
    {
        if (!in_array($file->mime, ['application/pdf', 'text/plain'], true)) {
            return false;
        }

        try {
            $text = $file->text();
        } catch (StatementException) {
            return false;
        }

        return str_contains($text, 'Transaction History') && 1 === preg_match('/^Selected Account:/mu', $text) && 1 === preg_match(self::HEADER, ltrim(self::headerLine($text)));
    }

    public function parse(StatementFile $file): ParsedStatement
    {
        $fields  = [];
        $rows    = [];
        $current = null;
        foreach (self::splitLines($file->text()) as $line) {
            if (1 === preg_match(self::FIELD, $line, $field)) {
                $fields[$field['label']] ??= $field['value'];

                continue;
            }
            if (1 === preg_match(self::ROW, $line, $row)) {
                $rows[]  = ['match' => $row, 'continuation' => [], 'raw' => [rtrim($line)]];
                $current = count($rows) - 1;
            } elseif (null !== $current && '' !== self::strip($line) && str_starts_with($line, ' ') && 1 !== preg_match(self::FOOTER, self::strip($line))) {
                $rows[$current]['continuation'][] = self::strip($line);
                $rows[$current]['raw'][]          = rtrim($line);
            } elseif ('' === self::strip($line) || 1 === preg_match(self::FOOTER, $line) || 1 === preg_match(self::HEADER, $line)) {
                $current = null;
            }
        }
        foreach (['Selected Account', 'Date'] as $required) {
            if (!array_key_exists($required, $fields)) {
                throw new StatementException(sprintf('Not an FNB Transaction History: "%s:" is missing.', $required));
            }
        }
        if ([] === $rows) {
            throw new StatementException('The statement has no transaction lines.');
        }

        $lines    = [];
        $warnings = [];
        foreach ($rows as $index => $row) {
            $match       = $row['match'];
            $description = implode(' ', [self::strip($match['desc']), ...$row['continuation']]);
            $card        = 1 === preg_match(self::CARD, $description, $cardMatch) ? substr($cardMatch[0], -4) : null;
            $lines[]     = new StatementLine(
                $index,
                self::date($match['date']),
                $description,
                self::money($match['amount']),
                self::decimal($match['fee']),
                self::money($match['balance']),
                $card,
                self::counterparty($description),
                implode("\n", $row['raw']),
            );
        }

        $count    = count($lines);
        $chain    = true;
        for ($i = 0; $i < $count - 1; ++$i) {
            if (0 !== bccomp($lines[$i]->balance, bcadd($lines[$i + 1]->balance, $lines[$i]->amount, 2), 2)) {
                $chain      = false;
                $warnings[] = sprintf('Balance chain breaks between line %d (%s) and line %d.', $i, $lines[$i]->date->format('Y-m-d'), $i + 1);
            }
        }
        $closing  = $lines[0]->balance;
        $opening  = bcsub($lines[$count - 1]->balance, $lines[$count - 1]->amount, 2);
        $current  = array_key_exists('Current Balance', $fields) ? self::money($fields['Current Balance']) : null;
        if (null !== $current && 0 !== bccomp($current, $closing, 2)) {
            $warnings[] = sprintf('The top line balance %s differs from the Current Balance %s.', $closing, $current);
        }

        // A non-zero service fee has not been seen in a sample yet, so it is not known whether the fee is part
        // of the balance chain. Until then it becomes an extra fee line, with a warning.
        foreach ($lines as $line) {
            if (0 !== bccomp($line->fee, '0', 2)) {
                $warnings[] = sprintf('Line %d has a service fee of %s; it was added as a separate fee line. Check it against the bank.', $line->postingIndex, $line->fee);
                $lines[]    = new StatementLine(count($lines), $line->date, 'Service fee: '.$line->description, '-'.ltrim($line->fee, '-'), '0.00', $line->balance, $line->cardRef, 'FNB', $line->raw, true);
            }
        }

        $statementDate = self::date($fields['Date']);
        $earliest      = $statementDate->copy();
        foreach ($lines as $line) {
            if ($line->date->lt($earliest)) {
                $earliest = $line->date->copy();
            }
        }

        return new ParsedStatement(
            bank: FnbBank::ID,
            parser: self::ID,
            accountNumber: preg_replace('/\D+/', '', $fields['Selected Account']) ?? '',
            statementDate: $statementDate,
            periodStart: $earliest,
            periodEnd: $statementDate->copy(),
            openingBalance: $opening,
            closingBalance: $closing,
            availableBalance: array_key_exists('Available Balance', $fields) ? self::money($fields['Available Balance']) : null,
            currentBalance: $current,
            chainValid: $chain,
            warnings: $warnings,
            lines: $lines,
        );
    }

    /**
     * Counterparty: PURCH lines lose "PURCH " and the card number; transfer/payment shapes keep the text after
     * the keyword; anything else is the whole description.
     */
    public static function counterparty(string $description): string
    {
        if (str_starts_with($description, 'PURCH ')) {
            $merchant = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(self::CARD, '', substr($description, 6))));

            return '' === $merchant ? $description : $merchant;
        }
        foreach (self::COUNTERPARTY_KEYWORDS as $keyword) {
            if (str_starts_with($description, $keyword.' ')) {
                $rest = trim(substr($description, strlen($keyword)));

                return '' === $rest ? $description : $rest;
            }
        }

        return $description;
    }

    /** "1,234.56 DR" -> "-1234.56"; CR (or no suffix) is positive. Like the reference money(). */
    private static function money(string $text): string
    {
        $value = ltrim(self::decimal(explode(' ', self::strip($text))[0]), '-');

        return str_ends_with(self::strip($text), 'DR') ? '-'.$value : $value;
    }

    /** str(Decimal(text.replace(',', ''))) for "-1,234.56"-shaped input. */
    private static function decimal(string $text): string
    {
        $text     = str_replace(',', '', self::strip($text));
        $negative = str_starts_with($text, '-');
        $text     = ltrim($text, '-');
        [$int, $fraction] = explode('.', $text) + [1 => ''];
        $int      = ltrim($int, '0');
        $int      = '' === $int ? '0' : $int;

        return ($negative ? '-' : '').$int.('' === $fraction ? '' : '.'.$fraction);
    }

    private static function date(string $text): Carbon
    {
        [$day, $month, $year] = explode(' ', self::strip($text));
        if (!array_key_exists($month, self::MONTHS) || !checkdate(self::MONTHS[$month], (int) $day, (int) $year)) {
            throw new StatementException(sprintf('Invalid date "%s".', $text));
        }

        return Carbon::create((int) $year, self::MONTHS[$month], (int) $day, 0, 0, 0, config('app.timezone'));
    }

    /** Python's str.strip(): all Unicode whitespace. */
    private static function strip(string $text): string
    {
        return (string) preg_replace('/^[\s\p{Z}\x{1c}-\x{1f}\x{85}]+|[\s\p{Z}\x{1c}-\x{1f}\x{85}]+$/u', '', $text);
    }

    /**
     * Python's str.splitlines(): \n, \r, \r\n, \v, \f, \x1c-\x1e, \x85,   and   all end a line,
     * and there is no empty element after a final line break.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        $parts = preg_split('/\r\n|[\n\r\x{0b}\x{0c}\x{1c}\x{1d}\x{1e}\x{85}\x{2028}\x{2029}]/u', $text) ?: [];
        if ([] !== $parts && '' === $parts[count($parts) - 1]) {
            array_pop($parts);
        }

        return $parts;
    }

    private static function headerLine(string $text): string
    {
        return 1 === preg_match('/^\f?Date\s+Description.*$/mu', $text, $match) ? $match[0] : '';
    }
}
