<?php

/*
 * MailImportSummary.php
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
 * What one MailImporter::run() did (printed by ext:mail:poll, flashed by "Poll now").
 */
final class MailImportSummary
{
    public ?string $skipped         = null;
    public ?string $error           = null;
    public ?string $labelError      = null;
    public string $query            = '';
    public int $fetched             = 0;
    public int $skippedMessages     = 0;
    public int $parsedTransactions  = 0;
    public int $labelled            = 0;

    /** @var array<string, int> */
    public array $messages          = [ParseResult::TRANSACTIONS => 0, ParseResult::IGNORED => 0, ParseResult::UNMATCHED => 0];

    /** @var array<string, int> */
    public array $transactions      = ['created' => 0, 'already_in_firefly' => 0, 'unmapped' => 0, 'failed' => 0, 'shadow_exists' => 0, 'shadow_would_create' => 0];

    public function __construct(public readonly string $mailbox, public readonly bool $dryRun, public readonly bool $shadow) {}

    public function line(): string
    {
        if (null !== $this->skipped) {
            return sprintf('%s: skipped (%s)', $this->mailbox, $this->skipped);
        }
        $parts = [sprintf('%d fetched, %d already seen', $this->fetched, $this->skippedMessages)];
        foreach ($this->messages as $outcome => $count) {
            $parts[] = sprintf('%d %s', $count, $outcome);
        }
        foreach ($this->transactions as $status => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, str_replace('_', ' ', $status));
            }
        }
        if ($this->labelled > 0) {
            $parts[] = sprintf('%d labelled', $this->labelled);
        }
        $mode  = $this->dryRun ? ' [dry run]' : ($this->shadow ? ' [shadow]' : '');

        return sprintf('%s%s: %s%s%s', $this->mailbox, $mode, implode(', ', $parts), null === $this->error ? '' : ' ERROR: '.$this->error, null === $this->labelError ? '' : ' '.$this->labelError);
    }
}
