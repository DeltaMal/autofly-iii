<?php

/*
 * BankController.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /ext/banks: one profile per bank (account match mode, default account, tags, authentication, enabled).
 */
final class BankController extends ExtController
{
    protected string $titleKey = 'ext::mail.banks';

    public function index(BankRegistry $banks, AccountRepositoryInterface $accounts): View
    {
        $accounts->setUser($this->user());
        $profiles = [];
        foreach ($banks->all() as $id => $bank) {
            $profiles[$id] = BankProfile::forBank($this->group(), $id);
        }

        return view('ext::banks.index', [
            'banks'          => $banks->all(),
            'profiles'       => $profiles,
            'accounts'       => $accounts->getActiveAccountsByType([AccountTypeEnum::ASSET->value, ...config('firefly.valid_liabilities')])->pluck('name', 'id')->all(),
            'extBreadcrumbs' => [(string) trans('ext::mail.banks') => route('ext.banks.index')],
        ]);
    }

    public function update(Request $request, string $bank, BankRegistry $banks, AccountRepositoryInterface $accounts): RedirectResponse
    {
        abort_unless($banks->has($bank), 404);
        $accounts->setUser($this->user());
        $valid   = $accounts->getActiveAccountsByType([AccountTypeEnum::ASSET->value, ...config('firefly.valid_liabilities')])->pluck('id')->all();
        $data    = $request->validate([
            'account_match'      => ['required', Rule::in(['prefix', 'suffix'])],
            'default_account_id' => ['nullable', 'integer', Rule::in($valid)],
            'tags'               => ['nullable', 'string', 'max:500'],
        ]);
        $profile = BankProfile::forBank($this->group(), $bank);
        $profile->fill([
            'account_match'         => $data['account_match'],
            'default_account_id'    => null === ($data['default_account_id'] ?? null) ? null : (int) $data['default_account_id'],
            'tags'                  => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($data['tags'] ?? '')))))),
            'require_authenticated' => $request->boolean('require_authenticated'),
            'enabled'               => $request->boolean('enabled'),
        ])->save();

        return redirect(route('ext.banks.index'))->with('success', (string) trans('ext::mail.bank_saved', ['bank' => $banks->get($bank)->name()]));
    }
}
