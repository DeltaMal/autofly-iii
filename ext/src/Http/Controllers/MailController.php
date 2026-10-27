<?php

/*
 * MailController.php
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

namespace FireflyIII\Ext\Http\Controllers;

use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Models\MailMessage;
use FireflyIII\Ext\Models\MailTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /ext/mail: the email activity log and its detail view, with Retry for failed or unmapped emails.
 */
final class MailController extends ExtController
{
    protected string $titleKey = 'ext::mail.activity';
    protected string $icon     = 'bi-envelope-paper';

    public const array STATUSES = [MailMessage::PENDING, MailMessage::IMPORTED, MailMessage::IGNORED, MailMessage::UNMATCHED, MailMessage::FAILED, MailMessage::SHADOW_EXISTS, MailMessage::SHADOW_WOULD_CREATE];

    public function index(Request $request): View
    {
        $status    = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null;
        $mailboxId = (int) $request->query('mailbox', 0);
        $messages  = MailMessage::query()->forGroup($this->group())->with(['mailbox', 'transactions'])
            ->when(null !== $status, static fn ($q) => $q->where('status', $status))
            ->when($mailboxId > 0, static fn ($q) => $q->where('mailbox_id', $mailboxId))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('ext::mail.index', [
            'messages'       => $messages,
            'mailboxes'      => Mailbox::query()->forGroup($this->group())->orderBy('name')->get(),
            'statuses'       => self::STATUSES,
            'status'         => $status,
            'mailboxId'      => $mailboxId,
            'extBreadcrumbs' => [(string) trans('ext::mail.activity') => route('ext.mail.index')],
        ]);
    }

    public function show(int $messageId): View
    {
        $message = $this->find($messageId);

        return view('ext::mail.show', [
            'message'        => $message,
            'transactions'   => $message->transactions()->with(['account', 'transactionGroup'])->get(),
            'canRetry'       => $this->retryable($message),
            'extBreadcrumbs' => [(string) trans('ext::mail.activity') => route('ext.mail.index'), (string) $message->subject => '#'],
        ]);
    }

    /**
     * Puts failed and unmapped transactions of this email back to pending (attempts 0) and polls its mailbox.
     */
    public function retry(int $messageId, MailImporter $importer): RedirectResponse
    {
        $message = $this->find($messageId);
        $message->transactions()->whereIn('status', [MailTransaction::FAILED, MailTransaction::UNMAPPED])->update(['status' => MailTransaction::PENDING, 'attempts' => 0, 'error' => null]);
        if (MailMessage::FAILED === $message->status) {
            $message->update(['status' => MailMessage::PENDING]);
        }
        @set_time_limit(120);
        $summary = $importer->run($message->mailbox()->firstOrFail());

        return redirect(route('ext.mail.show', [$message->id]))->with(null === $summary->error ? 'success' : 'error', $summary->line());
    }

    private function retryable(MailMessage $message): bool
    {
        return MailMessage::FAILED === $message->status
            || $message->transactions()->whereIn('status', [MailTransaction::FAILED, MailTransaction::UNMAPPED])->exists();
    }

    private function find(int $id): MailMessage
    {
        return MailMessage::query()->forGroup($this->group())->with('mailbox')->findOrFail($id);
    }
}
