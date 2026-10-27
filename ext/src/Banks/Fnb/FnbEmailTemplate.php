<?php

/*
 * FnbEmailTemplate.php
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
use FireflyIII\Ext\Mail\EmailTemplate;
use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\ParsedTransaction;
use FireflyIII\Ext\Mail\ParseResult;
use FireflyIII\Ext\Mail\TemplateHelpers;
use FireflyIII\Ext\Mail\Text;
use InvalidArgumentException;

/**
 * FNB "inContact" alert emails: a verbatim port of the Python importer's banks/fnb.py (ext/docs/06 section 3).
 * External ids must stay byte-identical: they are the dedup key against everything already imported.
 *
 * Every regex is PCRE with (*UCP) and /u, so \d, \s, \w and \b are Unicode-aware like Python 3's str patterns.
 */
final class FnbEmailTemplate implements EmailTemplate
{
    public const array SENDERS = ['incontact@fnb.co.za'];
    public const string DOMAIN = 'fnb.co.za';

    private const string PREFIX   = '/(*UCP)FNB\s*:-?\)\s*/u';
    private const string WHEN_END = '/(*UCP)\b\d{1,2} ?[A-Z][a-z]{2} \d{1,2}:\d{2}\b/u';

    private const string AMOUNT     = 'R ?(?P<amount>\d[\d, ]*\.\d{2})';
    private const string ACCOUNT    = '(?P<account_type>(?:(?! from ).)+?) ?(?:a\/c)?\.\.(?P<account>\d+)';
    private const string CARD       = 'using card\.\.(?P<card>\d+)\.';
    private const string AVAIL      = '(?: Avail R ?(?P<avail>-?\d[\d, ]*(?:\.\d{2})?)\.)?';
    private const string REF        = '(?: Ref\.(?P<ref>.*?)\.)?';
    private const string WHEN       = ' (?P<day>\d{1,2}) ?(?P<month>[A-Z][a-z]{2}) (?P<time>\d{1,2}:\d{2})';
    private const string TO_ACCOUNT = '(?P<to_type>(?:(?! @ ).)+?) ?(?:a\/c)?\.\.(?P<to_account>\d+)';

    /** @var null|list<array{name: string, pattern: string, kind: null|string, tags: list<string>}> */
    private static ?array $rules = null;

    public function __construct(private readonly string $timezone = '') {}

    public function bankId(): string
    {
        return FnbBank::ID;
    }

    public function matches(MailMessage $message): bool
    {
        return in_array(strtolower($message->sender), self::SENDERS, true);
    }

    public function isAuthentic(MailMessage $message, bool $required): bool
    {
        return !$required || TemplateHelpers::senderAuthenticated($message->authResults, self::DOMAIN);
    }

    /**
     * @return list<string> notification segments of one body
     */
    public static function splitNotifications(string $body): array
    {
        preg_match_all(self::PREFIX, $body, $matches, PREG_OFFSET_CAPTURE);
        $segments = [];
        $count    = count($matches[0]);
        for ($i = 0; $i < $count; ++$i) {
            $start   = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end     = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($body);
            $chunk   = substr($body, $start, $end - $start);
            $joined  = str_replace("\n", ' ', $chunk);
            $segment = 1 === preg_match(self::WHEN_END, $joined, $when, PREG_OFFSET_CAPTURE)
                ? substr($joined, 0, $when[0][1] + strlen($when[0][0]))
                : explode("\n", $chunk)[0];
            $segment = Text::normaliseLine($segment);
            if ('' !== $segment) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    public function parse(MailMessage $message, array $config = []): ParseResult
    {
        $segments     = [];
        foreach ($message->bodies as $body) {
            $segments = self::splitNotifications($body);
            if ([] !== $segments) {
                break; // the first body that yields segments wins
            }
        }
        if ([] === $segments) {
            $joined = implode("\n", $message->bodies);
            if (1 === preg_match('/(*UCP)FNB Transaction declined|Helping safeguard your account/iu', $joined)) {
                return new ParseResult([], [mb_substr($joined, 0, 300)], []);
            }

            return new ParseResult([], [], [mb_substr('' !== $joined ? $joined : $message->subject, 0, 1000)]);
        }

        $transactions = [];
        $ignored      = [];
        $unmatched    = [];
        $seen         = [];
        foreach ($segments as $segment) {
            $rule = null;
            foreach (self::rules() as $candidate) {
                if (1 === preg_match($candidate['pattern'], $segment, $match)) {
                    $rule = $candidate;

                    break;
                }
            }
            if (null === $rule) {
                $unmatched[] = $segment;

                continue;
            }
            if (null === $rule['kind']) {
                $ignored[] = $segment;

                continue;
            }

            try {
                $transactions[] = $this->transaction($rule, $match, $segment, $message, $config, $seen);
            } catch (InvalidArgumentException) {
                $unmatched[] = $segment;
            }
        }

        return new ParseResult($transactions, $ignored, $unmatched);
    }

    /**
     * FnbTemplate._transaction().
     *
     * @param array<string, int> $seen key => repeats so far in this email
     */
    private function transaction(array $rule, array $match, string $segment, MailMessage $message, array $config, array &$seen): ParsedTransaction
    {
        $g            = static fn (string $name): string => Text::strip((string) ($match[$name] ?? ''));
        $amount       = TemplateHelpers::parseAmount($g('amount'));
        $balance      = '' === $g('avail') ? null : TemplateHelpers::parseAmount($g('avail'));
        $timezone     = '' !== $this->timezone ? $this->timezone : (string) config('app.timezone');
        $occurredAt   = TemplateHelpers::inferDateTime($g('day'), $g('month'), $g('time'), $message->receivedAt, $timezone);
        $accountLabel = sprintf('%s ..%s', $g('account_type'), $g('account'));
        $card         = '' === $g('card') ? null : $g('card');
        $toAccount    = '' === $g('to_account') ? null : $g('to_account');

        $counterparty = match ($rule['name']) {
            'transfer'      => sprintf('%s ..%s', $g('to_type'), $g('to_account')),
            'card_reserved' => '' !== $g('merchant') ? $g('merchant') : 'Card purchase',
            'card_reversal' => 'Card reversal',
            default         => '' !== $g('ref') ? $g('ref') : ('' !== $g('channel') ? $g('channel') : 'FNB'),
        };
        $description  = 'transfer' === $rule['name'] ? sprintf('Transfer from %s to %s', $accountLabel, $counterparty) : $counterparty;
        $tags         = [...array_values((array) ($config['tags'] ?? [])), ...$rule['tags']];
        if ('paid_from' === $rule['name'] && 'eft' === mb_strtolower($g('channel'), 'UTF-8')) {
            $tags[] = 'fnb-eft';
        }
        $tags         = array_values(array_unique($tags));

        $key          = implode('|', [
            $rule['name'],
            $g('account'),
            $card ?? '',
            bcadd($amount, '0', 2),
            mb_strtoupper($counterparty, 'UTF-8'),
            $occurredAt->format('Y-m-d\TH:i'),
            $balance ?? '',
        ]);
        $repeat       = $seen[$key] ?? 0;
        $seen[$key]   = $repeat + 1;
        if ($repeat > 0) {
            $key .= '|'.$repeat;
        }

        return new ParsedTransaction(
            externalId: TemplateHelpers::stableId('fnb', $key),
            bankId: FnbBank::ID,
            rule: $rule['name'],
            kind: $rule['kind'],
            amount: $amount,
            currency: (string) ($config['currency'] ?? 'ZAR'),
            occurredAt: $occurredAt,
            description: $description,
            counterparty: $counterparty,
            accountRef: $g('account'),
            cardRef: $card,
            balanceAfter: $balance,
            tags: $tags,
            rawText: 'FNB :-) '.$segment,
            mailboxId: $message->mailboxId,
            messageId: $message->messageId,
            subject: $message->subject,
            receivedAt: $message->receivedAt->copy(),
            counterAccountRef: $toAccount,
            accountLabel: $accountLabel,
        );
    }

    /**
     * The rules, tried in order with search(); the first match wins.
     *
     * @return list<array{name: string, pattern: string, kind: null|string, tags: list<string>}>
     */
    private static function rules(): array
    {
        if (null !== self::$rules) {
            return self::$rules;
        }
        $re = static fn (string $body): string => '/(*UCP)'.$body.'/u';
        $a  = self::AMOUNT;
        $ac = self::ACCOUNT;

        return self::$rules = [
            ['name' => 'card_reserved', 'pattern' => $re("^{$a} reserved for purchase(?: @ (?P<merchant>.+?))? from {$ac} ".self::CARD.self::AVAIL.self::WHEN.'$'), 'kind' => 'withdrawal', 'tags' => ['fnb-reserved']],
            ['name' => 'card_reversal', 'pattern' => $re("^REVERSAL of {$a} for (?P<reason>.+?) from {$ac} ".self::CARD.self::AVAIL.self::WHEN.'$'), 'kind' => 'deposit', 'tags' => ['fnb-reversal']],
            ['name' => 'paid_from', 'pattern' => $re("^{$a} paid from {$ac} @ (?P<channel>.+?)\\.".self::AVAIL.self::REF.self::WHEN.'$'), 'kind' => 'withdrawal', 'tags' => ['fnb-payment']],
            ['name' => 'paid_to', 'pattern' => $re("^{$a} paid to {$ac} @ (?P<channel>.+?)\\.".self::AVAIL.self::REF.self::WHEN.'$'), 'kind' => 'deposit', 'tags' => ['fnb-received']],
            ['name' => 'transfer', 'pattern' => $re("^{$a} t\\/fer from {$ac} to ".self::TO_ACCOUNT.' @ (?P<channel>.+?)\\.'.self::AVAIL.self::REF.self::WHEN.'$'), 'kind' => 'transfer', 'tags' => ['fnb-transfer']],
            ['name' => 'electricity_token', 'pattern' => $re('Elec Amt:.*Credit Token:'), 'kind' => null, 'tags' => []],
            ['name' => 'payment_link', 'pattern' => $re('^Payment Link created\b'), 'kind' => null, 'tags' => []],
        ];
    }

    /** For tests and the golden harness: the base date-time inference with this template's time zone. */
    public static function inferDateTime(int|string $day, string $month, string $time, Carbon $receivedAt, string $timezone): Carbon
    {
        return TemplateHelpers::inferDateTime($day, $month, $time, $receivedAt, $timezone);
    }
}
