<?php

/*
 * PollMail.php
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

namespace FireflyIII\Ext\Console;

use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Models\Mailbox;
use Illuminate\Console\Command;

/**
 * ext:mail:poll: runs the email import for every enabled mailbox (or one). Scheduled every 15 minutes by
 * ExtServiceProvider. An error in one mailbox never stops the others.
 */
final class PollMail extends Command
{
    protected $signature   = 'ext:mail:poll {--mailbox= : id or name of one mailbox} {--dry-run : parse and resolve, save nothing} {--retry-failed : put failed transactions and emails back to pending}';
    protected $description = 'Import bank alert emails from the configured mailboxes.';

    public function handle(): int
    {
        $query = Mailbox::query()->where('enabled', true)->orderBy('id');
        if (null !== $this->option('mailbox') && '' !== (string) $this->option('mailbox')) {
            $wanted = (string) $this->option('mailbox');
            $query  = Mailbox::query()->where(static fn ($q) => $q->where('id', ctype_digit($wanted) ? (int) $wanted : 0)->orWhere('name', $wanted));
        }
        $mailboxes = $query->get();
        if (0 === $mailboxes->count()) {
            $this->info('No (enabled) mailboxes to poll.');

            return self::SUCCESS;
        }
        $importer  = app(MailImporter::class);
        $failed    = false;
        foreach ($mailboxes as $mailbox) {
            $summary = $importer->run($mailbox, (bool) $this->option('dry-run'), (bool) $this->option('retry-failed'));
            $failed  = $failed || null !== $summary->error;
            null === $summary->error ? $this->line($summary->line()) : $this->error($summary->line());
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
