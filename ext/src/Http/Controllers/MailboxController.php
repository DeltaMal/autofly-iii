<?php

/*
 * MailboxController.php
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

use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Mail\Gmail\GmailOAuth;
use FireflyIII\Ext\Mail\Gmail\GmailProvider;
use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Mail\MailProviderException;
use FireflyIII\Ext\Models\Mailbox;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * /ext/mailboxes: list, create/edit, connect to Gmail (OAuth), poll now.
 */
final class MailboxController extends ExtController
{
    protected string $titleKey = 'ext::mail.mailboxes';
    protected string $icon     = 'bi-envelope';

    public function index(BankRegistry $banks): View
    {
        return view('ext::mailboxes.index', [
            'mailboxes'      => Mailbox::query()->forGroup($this->group())->orderBy('name')->get(),
            'banks'          => $banks->all(),
            'hasClient'      => '' !== GmailOAuth::globalClient()['client_id'],
            'extBreadcrumbs' => [(string) trans('ext::mail.mailboxes') => route('ext.mailboxes.index')],
        ]);
    }

    public function create(BankRegistry $banks): View
    {
        return $this->form(new Mailbox(['banks' => array_keys($banks->all())]), $banks);
    }

    public function edit(int $mailboxId, BankRegistry $banks): View
    {
        return $this->form($this->find($mailboxId), $banks);
    }

    public function store(Request $request, BankRegistry $banks): RedirectResponse
    {
        $data    = $this->validated($request, $banks, null);
        $mailbox = Mailbox::create($data + ['user_group_id' => $this->user()->user_group_id, 'user_id' => $this->user()->id, 'provider' => 'gmail']);
        session()->flash('success', (string) trans('ext::mail.mailbox_saved', ['name' => $mailbox->name]));

        return redirect(route('ext.mailboxes.index'));
    }

    public function update(Request $request, int $mailboxId, BankRegistry $banks): RedirectResponse
    {
        $mailbox = $this->find($mailboxId);
        $data    = $this->validated($request, $banks, $mailbox);
        if ($mailbox->isShadow() && Mailbox::LIVE === $data['mode']) {
            MailImporter::leaveShadowMode($mailbox);
            session()->flash('info', (string) trans('ext::mail.went_live'));
        }
        $mailbox->update($data);
        session()->flash('success', (string) trans('ext::mail.mailbox_saved', ['name' => $mailbox->name]));

        return redirect(route('ext.mailboxes.index'));
    }

    public function destroy(int $mailboxId): RedirectResponse
    {
        $mailbox = $this->find($mailboxId);
        $mailbox->delete();
        session()->flash('success', (string) trans('ext::mail.mailbox_deleted', ['name' => $mailbox->name]));

        return redirect(route('ext.mailboxes.index'));
    }

    /** Redirects to Google with a state bound to this user and mailbox. */
    public function connect(int $mailboxId): RedirectResponse
    {
        $mailbox = $this->find($mailboxId);
        $client  = GmailOAuth::globalClient();
        if ('' === $client['client_id']) {
            return redirect(route('ext.mailboxes.index'))->with('error', (string) trans('ext::mail.no_client'));
        }
        $state   = Str::random(40);
        session()->put('ext_oauth_state', ['state' => $state, 'mailbox' => $mailbox->id, 'user' => $this->user()->id]);

        return redirect()->away(app(GmailOAuth::class)->authorizeUrl($client['client_id'], $state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = (array) session()->pull('ext_oauth_state', []);
        $state    = (string) $request->query('state', '');
        if ('' === $state || !hash_equals((string) ($expected['state'] ?? ''), $state) || (int) ($expected['user'] ?? 0) !== $this->user()->id) {
            return redirect(route('ext.mailboxes.index'))->with('error', (string) trans('ext::mail.oauth_state'));
        }
        $mailbox  = $this->find((int) $expected['mailbox']);
        if ('' !== (string) $request->query('error', '') || '' === (string) $request->query('code', '')) {
            return redirect(route('ext.mailboxes.index'))->with('error', (string) trans('ext::mail.oauth_denied', ['error' => (string) $request->query('error', 'no code')]));
        }

        try {
            $client          = GmailOAuth::globalClient();
            $mailbox->oauth  = app(GmailOAuth::class)->exchange((string) $request->query('code'), $client['client_id'], $client['client_secret']);
            if ('' === (string) $mailbox->oauth['refresh_token']) {
                return redirect(route('ext.mailboxes.index'))->with('error', (string) trans('ext::mail.no_refresh_token'));
            }
            $mailbox->save();
            $mailbox->email_address = app(GmailProvider::class, ['mailbox' => $mailbox, 'oauth' => app(GmailOAuth::class)])->profile();
            $mailbox->last_error    = null;
            $mailbox->save();
        } catch (MailProviderException $e) {
            return redirect(route('ext.mailboxes.index'))->with('error', $e->getMessage());
        }

        return redirect(route('ext.mailboxes.index'))->with('success', (string) trans('ext::mail.connected', ['name' => $mailbox->name, 'email' => (string) $mailbox->email_address]));
    }

    /** "Poll now": runs synchronously with a 120 s limit and flashes the summary. */
    public function poll(int $mailboxId, MailImporter $importer): RedirectResponse
    {
        $mailbox = $this->find($mailboxId);
        @set_time_limit(120);

        try {
            $summary = $importer->run($mailbox);
        } catch (Throwable $e) {
            return redirect(route('ext.mailboxes.index'))->with('error', $e->getMessage());
        }

        return redirect(route('ext.mailboxes.index'))->with(null === $summary->error ? 'success' : 'error', $summary->line());
    }

    private function form(Mailbox $mailbox, BankRegistry $banks): View
    {
        return view('ext::mailboxes.form', [
            'mailbox'        => $mailbox,
            'banks'          => $banks->all(),
            'extBreadcrumbs' => [(string) trans('ext::mail.mailboxes') => route('ext.mailboxes.index'), (string) ($mailbox->exists ? $mailbox->name : trans('ext::mail.new_mailbox')) => '#'],
        ]);
    }

    private function validated(Request $request, BankRegistry $banks, ?Mailbox $mailbox): array
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('ext_mailboxes', 'name')->where('user_group_id', $this->user()->user_group_id)->ignore($mailbox?->id)],
            'banks'           => ['required', 'array', 'min:1'],
            'banks.*'         => ['string', Rule::in(array_keys($banks->all()))],
            'query'           => ['nullable', 'string', 'max:1000'],
            'lookback_days'   => ['required', 'integer', 'between:1,3650'],
            'label_imported'  => ['required', 'string', 'max:100'],
            'label_unmatched' => ['required', 'string', 'max:100'],
            'mode'            => ['required', Rule::in([Mailbox::LIVE, Mailbox::SHADOW])],
            'enabled'         => ['nullable', 'boolean'],
        ]);
        $data['banks']   = array_values($data['banks']);
        $data['enabled'] = $request->boolean('enabled');
        $data['query']   = (string) ($data['query'] ?? '');

        return $data;
    }

    private function find(int $id): Mailbox
    {
        return Mailbox::query()->forGroup($this->group())->findOrFail($id);
    }
}
