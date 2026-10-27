<?php

/*
 * StatementController.php
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
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine;
use FireflyIII\Ext\Statements\StatementException;
use FireflyIII\Ext\Statements\StatementImporter;
use FireflyIII\Ext\Statements\UploadOutcome;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Models\Tag;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /ext/statements: upload, review and commit bank statements (ext/docs/02 "UI").
 */
final class StatementController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->middleware(static function ($request, $next) {
            app('view')->share('title', (string) trans('ext::statements.title'));
            app('view')->share('mainTitleIcon', 'bi-file-earmark-text');

            return $next($request);
        });
    }

    public function index(): View
    {
        $statements = Statement::query()->forGroup($this->user()->userGroup)->with('account')->orderByDesc('id')->paginate(25);

        return view('ext::statements.index', [
            'statements'     => $statements,
            'extBreadcrumbs' => [(string) trans('ext::statements.title') => route('ext.statements.index')],
        ]);
    }

    public function create(): View
    {
        return view('ext::statements.create', [
            'accounts'       => $this->assetAccounts(),
            'defaults'       => StatementImporter::DEFAULT_OPTIONS,
            'maxKb'          => (int) (config('ext.statements.max_upload_bytes') / 1024),
            'extBreadcrumbs' => [(string) trans('ext::statements.title') => route('ext.statements.index'), (string) trans('ext::statements.upload') => route('ext.statements.create')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $maxKb    = (int) (config('ext.statements.max_upload_bytes') / 1024);
        $data     = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'file'       => ['required', 'file', 'mimes:pdf', sprintf('max:%d', $maxKb)],
            'password'   => ['nullable', 'string', 'max:255'],
            'reprocess'  => ['nullable', 'boolean'],
        ]);
        $options  = [];
        foreach (array_keys(StatementImporter::DEFAULT_OPTIONS) as $option) {
            $options[$option] = $request->boolean('options.'.$option);
        }
        $accountId = null === ($data['account_id'] ?? null) || 0 === (int) $data['account_id'] ? null : (int) $data['account_id'];
        $upload    = $request->file('file');
        $importer  = new StatementImporter($this->user(), app(BankRegistry::class));

        try {
            $outcome = $importer->upload((string) $upload->getRealPath(), (string) $upload->getClientOriginalName(), 'application/pdf', $accountId, $data['password'] ?? null, $options, $request->boolean('reprocess'));
        } catch (StatementException $e) {
            return redirect(route('ext.statements.create'))->withInput($request->except(['password', 'file']))->withErrors(['file' => $e->getMessage()]);
        }

        return match ($outcome->kind) {
            UploadOutcome::NEEDS_ACCOUNT => redirect(route('ext.statements.create'))->withInput($request->except(['password', 'file']))->withErrors(['account_id' => (string) trans('ext::statements.choose_account', ['problem' => $outcome->notice])]),
            UploadOutcome::DUPLICATE     => redirect(route('ext.statements.create'))->withInput($request->except(['password', 'file']))->withErrors(['file' => $outcome->notice]),
            UploadOutcome::COMMITTED     => redirect(route('ext.statements.show', [$outcome->statement->id]))->with('success', (string) trans('ext::statements.auto_committed')),
            default                      => redirect(route('ext.statements.show', [$outcome->statement->id]))->with($outcome->notice ? 'warning' : 'info', $outcome->notice ?? (string) trans('ext::statements.parsed')),
        };
    }

    public function show(int $statementId): View
    {
        $statement = $this->find($statementId);
        $lines     = $statement->lines()->get();
        $tag       = null;
        foreach ((array) ($statement->summary['created_journals'] ?? []) as $journalId) {
            $tag = Tag::query()->where('user_group_id', $statement->user_group_id)->whereHas('transactionJournals', static fn ($q) => $q->where('transaction_journals.id', $journalId))->where('tag', 'like', 'Statement import %')->first();

            break;
        }

        $importer    = new StatementImporter($this->user(), app(BankRegistry::class));
        $breakdown   = $importer->currentBreakdown($statement);

        return view('ext::statements.show', [
            'statement'      => $statement,
            'breakdown'      => $breakdown,
            'unexplained'    => $importer->unexplained($statement, $breakdown),
            'decision'       => $statement->summary['unexplained']['decision'] ?? null,
            'missing'        => $lines->where('status', StatementLine::MISSING),
            'matched'        => $lines->whereIn('status', [StatementLine::MATCHED, StatementLine::CREATED]),
            'proposals'      => $lines->where('status', StatementLine::PROPOSAL),
            'ignored'        => $lines->where('status', StatementLine::IGNORED),
            'fireflyOnly'    => (array) ($statement->summary['firefly_only'] ?? []),
            'warnings'       => (array) ($statement->summary['warnings'] ?? []),
            'importTag'      => $tag,
            'extBreadcrumbs' => [(string) trans('ext::statements.title') => route('ext.statements.index'), $statement->file_name => route('ext.statements.show', [$statement->id])],
        ]);
    }

    public function commit(Request $request, int $statementId): RedirectResponse
    {
        $statement = $this->find($statementId);
        $ids       = static fn (string $key): array => array_values(array_map('intval', (array) $request->input($key, [])));
        $importer  = new StatementImporter($this->user(), app(BankRegistry::class));
        $statement = $importer->commit($statement, $ids('create'), $ids('apply'), $ids('ignore'));
        session()->flash('success', (string) trans('ext::statements.committed'));

        // the answer to "Book <amount> as Unexplained?", asked on the review page when the difference was not zero
        $answer    = (string) $request->input('book_unexplained', '');
        if ('' !== $answer) {
            $this->answer($importer, $statement, 'no' === $answer ? 'no' : 'yes', $answer);
        }

        return redirect(route('ext.statements.show', [$statement->id]));
    }

    /**
     * "Book <amount> as Unexplained?" on a committed statement: yes books it, no books nothing (and the page
     * keeps offering it).
     */
    public function unexplained(Request $request, int $statementId): RedirectResponse
    {
        $statement = $this->find($statementId);
        $data      = $request->validate(['answer' => ['required', 'in:yes,no'], 'amount' => ['required', 'string', 'max:40']]);
        $this->answer(new StatementImporter($this->user(), app(BankRegistry::class)), $statement, $data['answer'], $data['amount']);

        return redirect(route('ext.statements.show', [$statement->id]));
    }

    private function answer(StatementImporter $importer, Statement $statement, string $answer, string $amount): void
    {
        if ('no' === $answer) {
            $importer->declineUnexplained($statement);
            session()->flash('info', (string) trans('ext::statements.unexplained_declined'));

            return;
        }

        try {
            $importer->bookUnexplained($statement, $amount);
            session()->flash('success', (string) trans('ext::statements.unexplained_booked', ['amount' => $amount]));
        } catch (StatementException $e) {
            session()->flash('warning', $e->getMessage());
        }
    }

    private function find(int $id): Statement
    {
        return Statement::query()->forGroup($this->user()->userGroup)->with('account')->findOrFail($id);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function assetAccounts(): array
    {
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($this->user());
        $result     = [];
        foreach ($repository->getActiveAccountsByType([AccountTypeEnum::ASSET->value]) as $account) {
            $result[$account->id] = $account->name;
        }

        return $result;
    }
}
