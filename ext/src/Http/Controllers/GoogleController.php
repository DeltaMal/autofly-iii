<?php

/*
 * GoogleController.php
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

use FireflyIII\Ext\Mail\Gmail\GmailOAuth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /ext/admin/google (site owner only): the Google OAuth client used to connect Gmail mailboxes.
 */
final class GoogleController extends ExtController
{
    protected string $titleKey = 'ext::mail.google';
    protected string $icon     = 'bi-google';

    public function index(): View
    {
        $client = GmailOAuth::globalClient();

        return view('ext::admin.google', [
            'clientId'       => $client['client_id'],
            'hasSecret'      => null !== $client['client_secret'] && '' !== $client['client_secret'],
            'redirectUri'    => GmailOAuth::redirectUri(),
            'extBreadcrumbs' => [(string) trans('ext::mail.google') => route('ext.admin.google.index')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id'     => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],
        ]);
        GmailOAuth::setGlobalClient(trim($data['client_id']), null === ($data['client_secret'] ?? null) ? null : trim($data['client_secret']));

        return redirect(route('ext.admin.google.index'))->with('success', (string) trans('ext::mail.google_saved'));
    }
}
