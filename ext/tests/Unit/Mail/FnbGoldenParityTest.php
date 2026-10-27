<?php

/*
 * FnbGoldenParityTest.php
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

namespace FireflyIII\Ext\Tests\Unit\Mail;

use Carbon\Carbon;
use FireflyIII\Ext\Banks\Fnb\FnbEmailTemplate;
use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\TemplateHelpers;
use FireflyIII\Ext\Mail\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 100% parity with the Python importer: every vector of ext/tests/fixtures/fnb-email/golden.json, every
 * transaction field (external_id included). No skips.
 *
 * @internal
 */
#[CoversClass(FnbEmailTemplate::class)]
#[CoversClass(Text::class)]
#[CoversClass(TemplateHelpers::class)]
#[CoversClass(MailMessage::class)]
final class FnbGoldenParityTest extends TestCase
{
    private static ?array $golden = null;

    public static function golden(): array
    {
        return self::$golden ??= json_decode((string) file_get_contents(__DIR__.'/../../fixtures/fnb-email/golden.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function vectors(string $section): iterable
    {
        foreach (self::golden()[$section] as $index => $vector) {
            yield sprintf('%s #%d', $section, $index) => [$vector];
        }
    }

    public static function normaliseLineVectors(): iterable
    {
        return self::vectors('normalise_line');
    }

    public static function htmlToTextVectors(): iterable
    {
        return self::vectors('html_to_text');
    }

    public static function splitVectors(): iterable
    {
        return self::vectors('split_notifications');
    }

    public static function senderVectors(): iterable
    {
        return self::vectors('sender_authenticated');
    }

    public static function inferVectors(): iterable
    {
        return self::vectors('infer_datetime');
    }

    public static function messageVectors(): iterable
    {
        foreach (self::golden()['messages'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    #[DataProvider('normaliseLineVectors')]
    public function testNormaliseLine(array $vector): void
    {
        self::assertSame($vector['output'], Text::normaliseLine($vector['input']));
    }

    #[DataProvider('htmlToTextVectors')]
    public function testHtmlToText(array $vector): void
    {
        self::assertSame($vector['output'], Text::htmlToText($vector['input']));
    }

    #[DataProvider('splitVectors')]
    public function testSplitNotifications(array $vector): void
    {
        self::assertSame($vector['output'], FnbEmailTemplate::splitNotifications($vector['input']));
    }

    #[DataProvider('senderVectors')]
    public function testSenderAuthenticated(array $vector): void
    {
        self::assertSame($vector['expected'], TemplateHelpers::senderAuthenticated([$vector['header']], 'fnb.co.za'));
    }

    public function testOnlyTheTopmostAuthenticationHeaderCounts(): void
    {
        self::assertTrue(TemplateHelpers::senderAuthenticated([self::golden()['auth_pass_header']], 'fnb.co.za'));
        self::assertFalse(TemplateHelpers::senderAuthenticated(['mx.google.com; spf=fail', self::golden()['auth_pass_header']], 'fnb.co.za'));
        self::assertFalse(TemplateHelpers::senderAuthenticated([], 'fnb.co.za'));
    }

    #[DataProvider('inferVectors')]
    public function testInferDateTime(array $vector): void
    {
        $received = Carbon::parse($vector['received_at']);
        if (true === ($vector['expected_error'] ?? false)) {
            $this->expectException(InvalidArgumentException::class);
        }
        $result   = TemplateHelpers::inferDateTime($vector['day'], $vector['month'], $vector['time'], $received, self::golden()['timezone']);
        self::assertSame($vector['expected'], $result->format('Y-m-d\TH:i:sP'));
    }

    #[DataProvider('messageVectors')]
    public function testMessage(array $vector): void
    {
        $golden   = self::golden();
        $data     = $vector['message'];
        if (array_key_exists('parts', $vector)) {
            // the HTML/plain parts decode to exactly the bodies Python extracted
            self::assertSame($data['bodies'], MailMessage::bodiesFromParts($vector['parts']['html'] ?? null, $vector['parts']['plain'] ?? null));
        }
        $message  = new MailMessage($data['mailbox_id'], $data['message_id'], Carbon::parse($data['received_at']), $data['sender'], $data['subject'], $data['auth_results'], $data['bodies']);
        $template = new FnbEmailTemplate($golden['timezone']);
        $expected = $vector['expected'];

        self::assertSame($expected['matches'], $template->matches($message), 'matches');
        self::assertSame($expected['is_authentic'], $template->isAuthentic($message, (bool) $golden['bank_config']['require_authenticated']), 'is_authentic');
        self::assertSame($expected['is_authentic_without_check'], $template->isAuthentic($message, false), 'is_authentic_without_check');

        $result   = $template->parse($message, $golden['bank_config']);
        self::assertSame($expected['outcome'], $result->outcome(), 'outcome');
        self::assertSame($expected['ignored'], $result->ignored, 'ignored');
        self::assertSame($expected['unmatched'], $result->unmatched, 'unmatched');
        self::assertSame($expected['transactions'], array_map(static fn ($t): array => $t->toArray(), $result->transactions), 'transactions');
    }

    public function testParseIsStable(): void
    {
        // identical notifications in one email: distinct ids, stable on re-parse; HTML and plain give the same ids
        $golden  = self::golden();
        $byName  = array_column($golden['messages'], null, 'name');
        $twice   = $byName['identical_twice']['message'];
        $message = new MailMessage($twice['mailbox_id'], $twice['message_id'], Carbon::parse($twice['received_at']), $twice['sender'], $twice['subject'], $twice['auth_results'], $twice['bodies']);
        $first   = array_map(static fn ($t) => $t->externalId, (new FnbEmailTemplate($golden['timezone']))->parse($message, $golden['bank_config'])->transactions);
        $again   = array_map(static fn ($t) => $t->externalId, (new FnbEmailTemplate($golden['timezone']))->parse($message, $golden['bank_config'])->transactions);
        self::assertSame($first, $again);
        self::assertCount(2, array_unique($first));

        $alt     = $byName['eml:paid_to_alternative.eml']['message'];
        $ids     = [];
        foreach ($alt['bodies'] as $body) {
            $single = new MailMessage($alt['mailbox_id'], $alt['message_id'], Carbon::parse($alt['received_at']), $alt['sender'], $alt['subject'], $alt['auth_results'], [$body]);
            $ids[]  = (new FnbEmailTemplate($golden['timezone']))->parse($single, $golden['bank_config'])->transactions[0]->externalId;
        }
        self::assertCount(1, array_unique($ids));
    }
}
