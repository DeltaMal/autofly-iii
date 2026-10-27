<?php

/*
 * AccountIdentifierController.php
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

use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Models\Account;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /ext/accounts: every active asset and liability account, its identifiers per bank (partial account numbers
 * and card last-4s), and what the automatic number match would pick.
 */
final class AccountIdentifierController extends ExtController
{
    protected string $titleKey = 'ext::mail.accounts';
    protected string $icon     = 'bi-credit-card';

    public function index(Request $request, BankRegistry $banks): View
    {
        $resolver    = new AccountResolver($this->group());
        $accounts    = array_filter($resolver->accounts(), static fn (array $a): bool => $a['active']);
        $identifiers = AccountIdentifier::query()->forGroup($this->group())->orderBy('bank')->orderBy('kind')->orderBy('value')->get()->groupBy('account_id');
        $test        = AccountResolver::digits((string) $request->query('test', ''));
        $testBank    = (string) $request->query('bank', array_key_first($banks->all()));
        $resolution  = '' !== $test && $banks->has($testBank) ? $resolver->resolve($testBank, $test, false) : null;

        return view('ext::accounts.index', [
            'accounts'       => $accounts,
            'identifiers'    => $identifiers,
            'banks'          => $banks->all(),
            'test'           => $test,
            'testBank'       => $testBank,
            'resolution'     => $resolution,
            'extBreadcrumbs' => [(string) trans('ext::mail.accounts') => route('ext.accounts.index')],
        ]);
    }

    public function store(Request $request, BankRegistry $banks): RedirectResponse
    {
        $known = array_keys((new AccountResolver($this->group()))->accounts());
        $data  = $request->validate([
            'account_id' => ['required', 'integer', Rule::in($known)],
            'bank'       => ['required', Rule::in(array_keys($banks->all()))],
            'kind'       => ['required', Rule::in([AccountIdentifier::KIND_ACCOUNT_NUMBER, AccountIdentifier::KIND_CARD])],
            'value'      => ['required', 'string', 'max:64'],
        ]);
        $value = AccountResolver::digits($data['value']);
        if ('' === $value) {
            return redirect(route('ext.accounts.index'))->withErrors(['value' => (string) trans('ext::mail.digits_only')]);
        }
        AccountIdentifier::query()->updateOrCreate(
            ['user_group_id' => $this->user()->user_group_id, 'bank' => $data['bank'], 'kind' => $data['kind'], 'value' => $value],
            ['account_id' => (int) $data['account_id']]
        );

        return redirect(route('ext.accounts.index'))->with('success', (string) trans('ext::mail.identifier_saved', ['value' => $value, 'account' => (string) Account::find((int) $data['account_id'])?->name]));
    }

    public function destroy(int $identifierId): RedirectResponse
    {
        AccountIdentifier::query()->forGroup($this->group())->findOrFail($identifierId)->delete();

        return redirect(route('ext.accounts.index'))->with('success', (string) trans('ext::mail.identifier_deleted'));
    }
}
