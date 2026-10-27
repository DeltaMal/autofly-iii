<?php

/*
 * FakeMailProvider.php
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

namespace FireflyIII\Ext\Tests\Feature\Mail;

use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\MailProvider;
use FireflyIII\Ext\Mail\MailProviderException;

/**
 * In-memory mailbox for the pipeline tests.
 */
final class FakeMailProvider implements MailProvider
{
    /** @var array<string, int> */
    public array $fetches = [];

    /** @var list<array{ids: list<string>, add: list<string>, remove: list<string>}> */
    public array $labelCalls = [];

    /** @var list<string> */
    public array $queries = [];

    public bool $broken = false;

    /**
     * @param array<string, MailMessage> $messages
     */
    public function __construct(public array $messages = []) {}

    public function profile(): string
    {
        return 'owner@example.com';
    }

    public function search(string $query): array
    {
        if ($this->broken) {
            throw new MailProviderException('Google refused the refresh token (invalid_grant): reconnect the mailbox.', true);
        }
        $this->queries[] = $query;

        return array_keys($this->messages);
    }

    public function fetch(string $id): MailMessage
    {
        $this->fetches[$id] = ($this->fetches[$id] ?? 0) + 1;

        return $this->messages[$id];
    }

    public function fetchRaw(string $id): string
    {
        return "From: inContact@fnb.co.za\r\nSubject: raw\r\n\r\n".implode("\n", $this->messages[$id]->bodies);
    }

    public function modifyLabels(array $ids, array $add, array $remove): void
    {
        $this->labelCalls[] = ['ids' => $ids, 'add' => $add, 'remove' => $remove];
    }

    /** @return list<string> label names added to $id so far */
    public function labelsOf(string $id): array
    {
        $labels = [];
        foreach ($this->labelCalls as $call) {
            if (in_array($id, $call['ids'], true)) {
                $labels = array_values(array_diff(array_unique([...$labels, ...$call['add']]), $call['remove']));
            }
        }

        return $labels;
    }
}
