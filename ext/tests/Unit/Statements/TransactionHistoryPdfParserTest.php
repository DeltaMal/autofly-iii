<?php

/*
 * TransactionHistoryPdfParserTest.php
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

namespace FireflyIII\Ext\Tests\Unit\Statements;

use FireflyIII\Ext\Banks\Fnb\TransactionHistoryPdfParser;
use FireflyIII\Ext\Statements\ParsedStatement;
use FireflyIII\Ext\Statements\PdfTextExtractor;
use FireflyIII\Ext\Statements\StatementException;
use FireflyIII\Ext\Statements\StatementFile;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The anonymised FNB fixture must parse to exactly transaction-history.expected.json.
 * (Extends the feature test case only for config('app.timezone'); no database is used.)
 *
 * @internal
 */
#[CoversClass(TransactionHistoryPdfParser::class)]
final class TransactionHistoryPdfParserTest extends FeatureTestCase
{
    private const string FIXTURES = __DIR__.'/../../fixtures/fnb-statement';
    private const string PRIVATE  = __DIR__.'/../../fixtures/private';

    public function testAnonymisedFixtureParsesExactly(): void
    {
        $file     = StatementFile::fromText((string) file_get_contents(self::FIXTURES.'/transaction-history.layout.txt'));
        $parser   = new TransactionHistoryPdfParser();
        $expected = json_decode((string) file_get_contents(self::FIXTURES.'/transaction-history.expected.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($parser->supports($file));
        $parsed   = $parser->parse($file);

        self::assertSame($expected, self::asReference($parsed));
        self::assertSame([], $parsed->warnings);
        self::assertSame('12480.55', $parsed->currentBalance);
        self::assertSame('2025-06-17', $parsed->periodStart->format('Y-m-d'));
        self::assertSame('2025-07-14', $parsed->periodEnd->format('Y-m-d'));
    }

    public function testCounterparties(): void
    {
        $parsed = (new TransactionHistoryPdfParser())->parse(StatementFile::fromText((string) file_get_contents(self::FIXTURES.'/transaction-history.layout.txt')));
        $byText = [];
        foreach ($parsed->lines as $line) {
            $byText[$line->description] = $line->counterparty;
        }
        self::assertSame('PnP God Fehulo', $byText['PURCH PnP God Fehulo 400000******1234']);
        self::assertSame('MCD Zoloyeno (6366)', $byText['PURCH MCD Zoloyeno (6366) 400000******1234']);
        self::assertSame('MASARUREKADA', $byText['FNB APP TRANSFER FROM MASARUREKADA']);
        self::assertSame('FETUM', $byText['FNB APP PAYMENT FROM FETUM']);
        self::assertSame('LOSAVOMAVONEDATUGO', $byText['INTERNET TRF FROM LOSAVOMAVONEDATUGO']);
        self::assertSame('#MONTHLY ACCOUNT FEE', $byText['#MONTHLY ACCOUNT FEE']);
        self::assertSame('CREDITCARDSETTLE', $byText['CREDITCARDSETTLE']);
    }

    public function testBrokenChainAndFeesAreReported(): void
    {
        $text   = (string) file_get_contents(self::FIXTURES.'/transaction-history.layout.txt');
        // break the chain: change one amount; and give one line a service fee
        $text   = str_replace('-3,600.00 DR', '-3,601.00 DR', $text);
        $text   = (string) preg_replace('/(GOPILO\s+)0\.00/', '${1}5.50', $text);
        $parsed = (new TransactionHistoryPdfParser())->parse(StatementFile::fromText($text));

        self::assertFalse($parsed->chainValid);
        self::assertCount(61, $parsed->lines);
        $fee    = $parsed->lines[60];
        self::assertTrue($fee->isFeeLine);
        self::assertSame('-5.50', $fee->amount);
        self::assertCount(2, $parsed->warnings); // one chain break (line 1 -> 2) and one fee
    }

    public function testFormFeedPageBreaksAndOtherDocuments(): void
    {
        $text   = (string) file_get_contents(self::FIXTURES.'/transaction-history.layout.txt');
        $paged  = str_replace("\nDate                          Description            Service Fee", "\n\fDate                          Description            Service Fee", $text);
        self::assertSame(self::asReference((new TransactionHistoryPdfParser())->parse(StatementFile::fromText($text))), self::asReference((new TransactionHistoryPdfParser())->parse(StatementFile::fromText($paged))));

        self::assertFalse((new TransactionHistoryPdfParser())->supports(StatementFile::fromText('Some other bank statement')));
        $this->expectException(StatementException::class);
        (new TransactionHistoryPdfParser())->parse(StatementFile::fromText("Transaction History\nnothing here\n"));
    }

    public function testRealPrivateStatementWhenAvailable(): void
    {
        if (!is_file(self::PRIVATE.'/transaction-history.pdf') || !is_file(self::PRIVATE.'/transaction-history.expected.json')) {
            self::markTestSkipped('ext/tests/fixtures/private/ is not present (it never is in CI).');
        }
        if (null === shell_exec('command -v pdftotext')) {
            self::markTestSkipped('pdftotext (poppler-utils) is not installed.');
        }
        $file     = new StatementFile(self::PRIVATE.'/transaction-history.pdf', 'application/pdf', null, static fn (StatementFile $f): string => (new PdfTextExtractor())->extract($f->path));
        $expected = json_decode((string) file_get_contents(self::PRIVATE.'/transaction-history.expected.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expected, self::asReference((new TransactionHistoryPdfParser())->parse($file)));
    }

    /** The shape written by ext/scripts/anonymise_fnb_history.py parse(). */
    private static function asReference(ParsedStatement $parsed): array
    {
        $lines = [];
        foreach ($parsed->lines as $line) {
            if ($line->isFeeLine) {
                continue;
            }
            $lines[] = [
                'posting_index' => $line->postingIndex,
                'date'          => $line->date->format('Y-m-d'),
                'description'   => $line->description,
                'fee'           => $line->fee,
                'amount'        => $line->amount,
                'balance'       => $line->balance,
                'card_ref'      => $line->cardRef,
            ];
        }

        return [
            'account_number'    => $parsed->accountNumber,
            'statement_date'    => $parsed->statementDate->format('Y-m-d'),
            'available_balance' => $parsed->availableBalance,
            'closing_balance'   => $parsed->closingBalance,
            'opening_balance'   => $parsed->openingBalance,
            'sum_of_amounts'    => $parsed->sumOfAmounts(),
            'line_count'        => count($lines),
            'chain_valid'       => $parsed->chainValid,
            'lines'             => $lines,
        ];
    }
}
