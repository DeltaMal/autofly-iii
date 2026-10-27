<?php

/*
 * StatementImporter.php
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

namespace FireflyIII\Ext\Statements;

use Carbon\Carbon;
use FireflyIII\Ext\Banks\AccountResolver;
use FireflyIII\Ext\Banks\BankRegistry;
use FireflyIII\Ext\Import\DuplicateExternalIdException;
use FireflyIII\Ext\Import\ReconciliationAdjuster;
use FireflyIII\Ext\Import\TransactionRow;
use FireflyIII\Ext\Import\TransactionWriter;
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine as StatementLineModel;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Upload -> parse -> resolve the account -> analyse (Reconciler) -> persist; and commit (ext/docs/02).
 */
final class StatementImporter
{
    public const array DEFAULT_OPTIONS = [
        'import_missing'   => true,
        'reconcile'        => true,
        'adjust'           => false,
        'apply_rules'      => true,
        'auto_commit'      => false,
    ];

    public function __construct(private readonly User $user, private readonly BankRegistry $banks) {}

    /**
     * @param array<string, bool> $options
     *
     * @throws StatementException
     */
    public function upload(string $path, string $fileName, string $mime, ?int $accountId, #[\SensitiveParameter] ?string $password, array $options, bool $reprocess = false, ?StatementFile $file = null): UploadOutcome
    {
        $options  = array_merge(self::DEFAULT_OPTIONS, array_intersect_key($options, self::DEFAULT_OPTIONS));
        $contents = (string) file_get_contents($path);
        $sha256   = hash('sha256', $contents);
        $file   ??= new StatementFile($path, $mime, $password);
        $found    = $this->banks->parserFor($file);
        if (null === $found) {
            throw new StatementException('This file is not a supported bank statement.');
        }
        [$bank, $parser] = $found;
        $parsed   = $parser->parse($file);
        unset($password);

        // the account: given, or detected from the statement's account number (never the default account)
        $resolver = new AccountResolver($this->user->userGroup);
        if (null === $accountId) {
            $resolution = $resolver->resolve($bank->id(), $parsed->accountNumber, false);
            if (!$resolution->resolved()) {
                return UploadOutcome::needsAccount($resolution->problem ?? 'The account could not be detected.');
            }
            $accountId  = $resolution->accountId;
        }
        $account  = Account::query()->where('user_group_id', $this->user->user_group_id)->find($accountId);
        if (null === $account || !array_key_exists($account->id, $resolver->accounts())) {
            throw new StatementException('Choose one of your asset accounts.');
        }
        if (!$reprocess && Statement::query()->forGroup($this->user->userGroup)->where('account_id', $account->id)->where('file_sha256', $sha256)->exists()) {
            return UploadOutcome::duplicate();
        }

        $statement = DB::transaction(function () use ($account, $bank, $parser, $parsed, $fileName, $sha256, $contents, $options): Statement {
            $relative  = sprintf('%s/%s.bin', trim((string) config('ext.statements.storage_dir'), '/'), $sha256);
            Storage::disk((string) config('ext.statements.storage_disk'))->put($relative, Crypt::encrypt($contents));
            $statement = Statement::create([
                'user_group_id'     => $this->user->user_group_id,
                'user_id'           => $this->user->id,
                'account_id'        => $account->id,
                'bank'              => $bank->id(),
                'parser'            => $parser->id(),
                'file_name'         => mb_substr($fileName, 0, 255),
                'file_sha256'       => $sha256,
                'file_path'         => $relative,
                'statement_date'    => $parsed->statementDate,
                'period_start'      => $parsed->periodStart,
                'period_end'        => $parsed->periodEnd,
                'opening_balance'   => $parsed->openingBalance,
                'closing_balance'   => $parsed->closingBalance,
                'available_balance' => $parsed->availableBalance,
                'chain_valid'       => $parsed->chainValid,
                'status'            => Statement::STATUS_PARSED,
                'options'           => $options,
                'summary'           => null,
            ]);
            $result    = (new Reconciler($account))->analyse($parsed, $statement->id);
            foreach ($result->lines as $outcome) {
                StatementLineModel::create([
                    'user_group_id'          => $this->user->user_group_id,
                    'statement_id'           => $statement->id,
                    'posting_index'          => $outcome->line->postingIndex,
                    'date'                   => $outcome->line->date,
                    'description'            => mb_substr($outcome->line->description, 0, 1024),
                    'amount'                 => $outcome->line->amount,
                    'fee'                    => $outcome->line->fee,
                    'balance'                => $outcome->line->balance,
                    'card_ref'               => $outcome->line->cardRef,
                    'counterparty'           => mb_substr($outcome->line->counterparty, 0, 1024),
                    'raw'                    => $outcome->line->raw,
                    'is_fee_line'            => $outcome->line->isFeeLine,
                    'line_hash'              => $outcome->hash,
                    'status'                 => $outcome->status,
                    'transaction_journal_id' => $outcome->journalId,
                    'match_score'            => $outcome->score,
                    'proposal'               => $outcome->proposal,
                ]);
            }
            $statement->summary = ['phase' => 'review', 'counts' => $result->counts(), 'breakdown' => $result->breakdown, 'firefly_only' => $result->fireflyOnly, 'warnings' => $parsed->warnings];
            $statement->save();

            return $statement;
        });

        if ($options['auto_commit']) {
            $blocked = $this->autoCommitBlocker($statement);
            if (null !== $blocked) {
                return UploadOutcome::review($statement, $blocked);
            }
            $this->commit($statement);

            return UploadOutcome::committed($statement->refresh());
        }

        return UploadOutcome::review($statement);
    }

    public function autoCommitBlocker(Statement $statement): ?string
    {
        if (!$statement->chain_valid) {
            return 'The balance chain of the statement is broken, so it was not committed automatically.';
        }
        if ($statement->lines()->where('status', StatementLineModel::PROPOSAL)->exists()) {
            return 'Some lines need a decision (proposals), so the statement was not committed automatically.';
        }

        return null;
    }

    /**
     * Commits a reviewed statement in one DB transaction. Idempotent: a committed statement is left alone.
     *
     * @param null|list<int> $createLineIds     missing lines to create (null = all of them)
     * @param list<int>      $confirmedProposals proposal lines whose journal amount may be updated
     * @param list<int>      $ignoreLineIds     lines the user marked as ignored
     */
    public function commit(Statement $statement, ?array $createLineIds = null, array $confirmedProposals = [], array $ignoreLineIds = []): Statement
    {
        if (Statement::STATUS_COMMITTED === $statement->status) {
            return $statement;
        }
        $options   = array_merge(self::DEFAULT_OPTIONS, (array) $statement->options);
        $account   = $statement->account()->firstOrFail();

        DB::transaction(function () use ($statement, $options, $account, $createLineIds, $confirmedProposals, $ignoreLineIds): void {
            $writer       = new TransactionWriter($this->user, $this->user->userGroup, TransactionWriter::SOURCE_STATEMENT);
            $created      = [];
            $updated      = [];
            $lines        = $statement->lines()->get();

            foreach ($lines as $line) {
                if (in_array($line->id, $ignoreLineIds, true) && in_array($line->status, [StatementLineModel::MISSING, StatementLineModel::PROPOSAL], true)) {
                    $line->status = StatementLineModel::IGNORED;
                    $line->save();
                }
            }
            // 1. create missing lines
            foreach ($lines as $line) {
                if (StatementLineModel::MISSING !== $line->status) {
                    continue;
                }
                if (!$options['import_missing'] || (null !== $createLineIds && !in_array($line->id, $createLineIds, true))) {
                    continue;
                }
                $externalId = 'stmt-'.substr($line->line_hash, 0, 32);
                try {
                    $group = $writer->store(new TransactionRow(
                        account: $account,
                        direction: -1 === bccomp((string) $line->amount, '0', 2) ? 'out' : 'in',
                        amount: ltrim(bcadd((string) $line->amount, '0', 2), '-'),
                        date: $line->date->copy()->setTimezone(config('app.timezone'))->startOfDay(),
                        description: $line->description,
                        counterpartyName: '' === (string) $line->counterparty ? $line->description : (string) $line->counterparty,
                        tags: array_values(array_unique(['statement-import', $statement->bank])),
                        notes: sprintf("%s\n\nStatement: %s", (string) $line->raw, $statement->file_name),
                        externalId: $externalId,
                        applyRules: (bool) $options['apply_rules'],
                    ));
                    $journalId = (int) $group->transactionJournals()->first()?->id;
                } catch (DuplicateExternalIdException $e) {
                    // created by an earlier commit of an overlapping statement: link it, create nothing
                    $journalId = (int) TransactionJournal::withTrashed()->where('transaction_group_id', $e->groupId)->value('id');
                    Log::info(sprintf('Statement line %d: %s', $line->id, $e->getMessage()));
                }
                $line->status                 = StatementLineModel::CREATED;
                $line->transaction_journal_id = $journalId;
                $line->save();
                $created[]                    = $journalId;
            }

            // 2. confirmed proposals: update the journal amount through upstream's update service
            /** @var TransactionGroupRepositoryInterface $groups */
            $groups       = app(TransactionGroupRepositoryInterface::class);
            $groups->setUser($this->user);
            $groups->setUserGroup($this->user->userGroup);
            foreach ($lines as $line) {
                if (StatementLineModel::PROPOSAL !== $line->status || !in_array($line->id, $confirmedProposals, true)) {
                    continue;
                }
                $journal = TransactionJournal::query()->where('user_group_id', $this->user->user_group_id)->find($line->transaction_journal_id);
                if (null === $journal) {
                    continue;
                }
                $groups->update($journal->transactionGroup, ['transactions' => [['transaction_journal_id' => $journal->id, 'amount' => ltrim(bcadd((string) $line->amount, '0', 2), '-')]]]);
                $line->status = StatementLineModel::MATCHED;
                $line->save();
                $updated[]    = $journal->id;
            }

            // 3. reconcile matched and created journals
            $reconciled   = 0;
            if ($options['reconcile']) {
                /** @var JournalRepositoryInterface $journals */
                $journals = app(JournalRepositoryInterface::class);
                $journals->setUser($this->user);
                foreach ($statement->lines()->whereIn('status', [StatementLineModel::MATCHED, StatementLineModel::CREATED])->whereNotNull('transaction_journal_id')->get() as $line) {
                    $journals->reconcileById((int) $line->transaction_journal_id);
                    ++$reconciled;
                }
            }

            // 4. the breakdown after the changes, and an optional adjustment
            $parsed       = self::toParsed($statement);
            $reconciler   = new Reconciler($account);
            $outcomes     = array_map(static fn (StatementLineModel $l): LineOutcome => new LineOutcome(self::toLine($l), $l->line_hash, $l->status, $l->transaction_journal_id, null, $l->proposal), $statement->lines()->get()->all());
            $fireflyOnly  = (array) ($statement->summary['firefly_only'] ?? []);
            $breakdown    = $reconciler->breakdown($parsed, $outcomes, $fireflyOnly);
            $adjustment   = null;
            if ($options['adjust'] && 0 !== bccomp($breakdown['unexplained'], '0', 2)) {
                $group      = (new ReconciliationAdjuster($this->user))->book($account, $statement->statement_date->copy()->setTimezone(config('app.timezone'))->endOfDay()->milli(0), $breakdown['unexplained'], sprintf('Statement reconciliation (%s)', $statement->file_name));
                $adjustment = $group?->id;
                $breakdown  = $reconciler->breakdown($parsed, $outcomes, $fireflyOnly);
            }

            $counts                   = [];
            foreach ($statement->lines()->get() as $line) {
                $counts[$line->status] = ($counts[$line->status] ?? 0) + 1;
            }
            $statement->summary       = array_merge((array) $statement->summary, [
                'phase'             => 'committed',
                'counts'            => $counts,
                'breakdown'         => $breakdown,
                'created_journals'  => $created,
                'updated_journals'  => $updated,
                'reconciled'        => $reconciled,
                'adjustment_group'  => $adjustment,
                'committed_at'      => Carbon::now()->toIso8601String(),
            ]);
            $statement->status        = Statement::STATUS_COMMITTED;
            $statement->save();
        });

        return $statement->refresh();
    }

    /** Rebuilds the parsed statement from the stored rows (for the breakdown after commit). */
    public static function toParsed(Statement $statement): ParsedStatement
    {
        $lines = array_map(self::toLine(...), $statement->lines()->get()->all());

        return new ParsedStatement(
            $statement->bank,
            $statement->parser,
            '',
            $statement->statement_date->copy()->setTimezone(config('app.timezone')),
            $statement->period_start->copy()->setTimezone(config('app.timezone')),
            $statement->period_end->copy()->setTimezone(config('app.timezone')),
            (string) $statement->opening_balance,
            (string) $statement->closing_balance,
            null === $statement->available_balance ? null : (string) $statement->available_balance,
            null,
            $statement->chain_valid,
            (array) ($statement->summary['warnings'] ?? []),
            $lines,
        );
    }

    private static function toLine(StatementLineModel $line): StatementLine
    {
        return new StatementLine(
            $line->posting_index,
            Carbon::parse($line->date->format('Y-m-d'), config('app.timezone')),
            $line->description,
            bcadd((string) $line->amount, '0', 2),
            bcadd((string) $line->fee, '0', 2),
            bcadd((string) $line->balance, '0', 2),
            $line->card_ref,
            (string) $line->counterparty,
            (string) $line->raw,
            $line->is_fee_line,
        );
    }
}
