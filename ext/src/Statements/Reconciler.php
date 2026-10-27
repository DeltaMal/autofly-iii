<?php

/*
 * Reconciler.php
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
use FireflyIII\Ext\Models\Statement;
use FireflyIII\Ext\Models\StatementLine as StatementLineModel;
use FireflyIII\Models\Account;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches statement lines to Firefly journals on one account (ext/docs/02 "Matching and auto-recon").
 *
 * - line identity: sha256(account|date|signed amount|balance|normalised description); a line already linked on
 *   an earlier statement of the account keeps its link, so overlapping uploads never create duplicates;
 * - candidates: journals on the account dated line.date-5 .. line.date+2 with exactly the signed amount, not
 *   linked to a line of another statement; a bank charge ("#" row or fee line) only matches a journal that is
 *   itself a bank charge: created from the same row (its stmt- external id) or booked against the bank;
 * - score: date distance (dominant), + card ref == internal_reference, + token overlap, + email external id;
 * - one-to-one greedy assignment by score, ties by posting index then journal id;
 * - unmatched lines: a "proposal" when a nearby journal with the same card or strong token overlap has a
 *   different amount, otherwise "missing";
 * - Firefly-only journals in the statement window: pending, reversed_pair or unexplained.
 */
final class Reconciler
{
    public const int DAYS_BEFORE          = 5;
    public const int DAYS_AFTER           = 2;
    public const int PROPOSAL_DAYS        = 2;
    public const int PENDING_DAYS         = 3;
    public const int REVERSAL_DAYS        = 7;
    public const float STRONG_OVERLAP     = 0.5;

    /**
     * @param ?string $bankName the bank's name (BankDefinition::name()): bank charges are booked against it
     */
    public function __construct(private readonly Account $account, private readonly ?string $bankName = null) {}

    public static function externalIdFor(string $lineHash): string
    {
        return 'stmt-'.substr($lineHash, 0, 32);
    }

    public static function lineHash(int $accountId, Carbon $date, string $amount, string $balance, string $description): string
    {
        $normalised = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $description)), 'UTF-8');

        return hash('sha256', implode('|', [$accountId, $date->format('Y-m-d'), bcadd($amount, '0', 2), bcadd($balance, '0', 2), $normalised]));
    }

    /**
     * @param ?int $statementId the statement being analysed (its own lines don't count as "earlier" links)
     */
    public function analyse(ParsedStatement $parsed, ?int $statementId = null): ReconcileResult
    {
        $lines         = $parsed->lines;
        $hashes        = [];
        foreach ($lines as $line) {
            $hashes[$line->postingIndex] = self::lineHash($this->account->id, $line->date, $line->amount, $line->balance, $line->description);
        }

        // journals already linked to lines of other statements of this account
        $linked        = StatementLineModel::query()
            ->join('ext_statements', 'ext_statements.id', '=', 'ext_statement_lines.statement_id')
            ->where('ext_statements.account_id', $this->account->id)
            ->when(null !== $statementId, static fn ($q) => $q->where('ext_statements.id', '!=', $statementId))
            ->whereIn('ext_statement_lines.status', [StatementLineModel::MATCHED, StatementLineModel::CREATED])
            ->whereNotNull('ext_statement_lines.transaction_journal_id')
            ->orderBy('ext_statement_lines.id')
            ->get(['ext_statement_lines.line_hash', 'ext_statement_lines.transaction_journal_id']);
        $earlierByHash = [];
        $linkedIds     = [];
        foreach ($linked as $row) {
            $earlierByHash[$row->line_hash] ??= (int) $row->transaction_journal_id;
            $linkedIds[(int) $row->transaction_journal_id] = true;
        }

        $minDate       = $parsed->periodStart->copy()->subDays(self::DAYS_BEFORE + self::REVERSAL_DAYS)->startOfDay();
        $maxDate       = $parsed->periodEnd->copy()->addDays(self::DAYS_AFTER + self::REVERSAL_DAYS)->endOfDay();
        $journals      = $this->journals($minDate, $maxDate);
        $outcomes      = [];
        $taken         = [];

        // 1. lines linked on an earlier statement keep that link
        foreach ($lines as $line) {
            $journalId = $earlierByHash[$hashes[$line->postingIndex]] ?? null;
            if (null !== $journalId && array_key_exists($journalId, $journals)) {
                $outcomes[$line->postingIndex] = new LineOutcome($line, $hashes[$line->postingIndex], StatementLineModel::MATCHED, $journalId, null, null, true);
                $taken[$journalId]             = true;
            }
        }

        // 2. candidate pairs, scored
        $pairs         = [];
        foreach ($lines as $line) {
            if (array_key_exists($line->postingIndex, $outcomes)) {
                continue;
            }
            foreach ($journals as $journal) {
                if (array_key_exists($journal['id'], $taken) || array_key_exists($journal['id'], $linkedIds)) {
                    continue;
                }
                $days = (int) $line->date->copy()->startOfDay()->diffInDays($journal['date']->copy()->startOfDay(), false);
                if ($days < -self::DAYS_BEFORE || $days > self::DAYS_AFTER || 0 !== bccomp($journal['amount'], $line->amount, 2)) {
                    continue;
                }
                if ($line->isBankCharge() && !$this->isBankChargeJournal($journal, $hashes[$line->postingIndex])) {
                    continue; // never a card or EFT transaction that merely has the same amount
                }
                $pairs[]  = [$this->score($line, $journal, abs($days)), $line->postingIndex, $journal['id']];
            }
        }
        // 3. greedy one-to-one assignment: score desc, then posting index, then journal id
        usort($pairs, static fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $byIndex       = [];
        foreach ($lines as $line) {
            $byIndex[$line->postingIndex] = $line;
        }
        foreach ($pairs as [$score, $index, $journalId]) {
            if (array_key_exists($index, $outcomes) || array_key_exists($journalId, $taken)) {
                continue;
            }
            $outcomes[$index]  = new LineOutcome($byIndex[$index], $hashes[$index], StatementLineModel::MATCHED, $journalId, $score);
            $taken[$journalId] = true;
        }

        // 4. proposals and missing lines
        foreach ($lines as $line) {
            if (array_key_exists($line->postingIndex, $outcomes)) {
                continue;
            }
            $proposal = $line->isBankCharge() ? null : $this->proposal($line, $journals, $taken + $linkedIds);
            if (null !== $proposal) {
                $taken[$proposal['journal_id']]  = true;
                $outcomes[$line->postingIndex]   = new LineOutcome($line, $hashes[$line->postingIndex], StatementLineModel::PROPOSAL, $proposal['journal_id'], null, $proposal);

                continue;
            }
            $outcomes[$line->postingIndex] = new LineOutcome($line, $hashes[$line->postingIndex], StatementLineModel::MISSING);
        }
        ksort($outcomes);

        // 5. Firefly-only journals inside the statement window
        $fireflyOnly   = $this->fireflyOnly($journals, $taken, $parsed);

        return new ReconcileResult(array_values($outcomes), $fireflyOnly, $this->breakdown($parsed, array_values($outcomes), $fireflyOnly));
    }

    /**
     * The balance breakdown on top of the review page. The unexplained difference is
     * closing - (firefly - pending + missing + proposal changes); it is 0 once everything is explained.
     *
     * @param list<LineOutcome> $outcomes
     * @param list<array>       $fireflyOnly
     *
     * @return array<string, string>
     */
    public function breakdown(ParsedStatement $parsed, array $outcomes, array $fireflyOnly, bool $proposalsApplied = false): array
    {
        $firefly   = $this->fireflyBalance($parsed->statementDate);
        $pending   = '0.00';
        foreach ($fireflyOnly as $journal) {
            if ('pending' === $journal['kind']) {
                $pending = bcadd($pending, $journal['amount'], 2);
            }
        }
        $missing   = '0.00';
        $proposals = '0.00';
        foreach ($outcomes as $outcome) {
            if (StatementLineModel::MISSING === $outcome->status) {
                $missing = bcadd($missing, $outcome->line->amount, 2);
            }
            if (StatementLineModel::PROPOSAL === $outcome->status && !$proposalsApplied) {
                $proposals = bcadd($proposals, bcsub($outcome->proposal['to'], $outcome->proposal['from'], 2), 2);
            }
        }
        $expected  = bcadd(bcadd(bcsub($firefly, $pending, 2), $missing, 2), $proposals, 2);

        return [
            'closing_balance'     => bcadd($parsed->closingBalance, '0', 2),
            'firefly_balance'     => $firefly,
            'pending'             => $pending,
            'missing'             => $missing,
            'proposals'           => $proposals,
            'expected_balance'    => $expected,
            'unexplained'         => bcsub(bcadd($parsed->closingBalance, '0', 2), $expected, 2),
            'available_balance'   => null === $parsed->availableBalance ? '' : bcadd($parsed->availableBalance, '0', 2),
        ];
    }

    /** Firefly's balance of the account at the end of $date, the same call as upstream's ReconcileController. */
    public function fireflyBalance(Carbon $date): string
    {
        $balances = Steam::accountsBalancesOptimized(new Collection()->push($this->account), $date->copy()->endOfDay());

        return bcadd(Steam::bcround((string) ($balances[$this->account->id]['balance'] ?? '0'), 2), '0', 2);
    }

    /**
     * Journals touching the account between two dates, seen from the account (signed amount).
     *
     * @return array<int, array{id: int, group_id: int, date: Carbon, amount: string, description: string, opposing: string, internal_reference: string, external_id: string, type: string}>
     */
    public function journals(Carbon $from, Carbon $to): array
    {
        $rows   = DB::table('transactions')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->join('transaction_types', 'transaction_types.id', '=', 'transaction_journals.transaction_type_id')
            ->where('transactions.account_id', $this->account->id)
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
            ->where('transaction_journals.date', '>=', $from->format('Y-m-d H:i:s'))
            ->where('transaction_journals.date', '<=', $to->format('Y-m-d H:i:s'))
            ->orderBy('transaction_journals.date')
            ->orderBy('transaction_journals.id')
            ->get([
                'transaction_journals.id', 'transaction_journals.transaction_group_id', 'transaction_journals.date', 'transaction_journals.description',
                'transactions.amount', 'transactions.id as transaction_id', 'transaction_types.type',
            ]);
        $ids    = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $meta   = [];
        $other  = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('journal_meta')->whereIn('transaction_journal_id', $chunk)->whereIn('name', ['internal_reference', 'external_id'])->whereNull('deleted_at')->get() as $row) {
                $meta[(int) $row->transaction_journal_id][$row->name] = (string) json_decode((string) $row->data, true);
            }
            $opposing = DB::table('transactions')
                ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
                ->whereIn('transactions.transaction_journal_id', $chunk)
                ->where('transactions.account_id', '!=', $this->account->id)
                ->whereNull('transactions.deleted_at')
                ->get(['transactions.transaction_journal_id', 'accounts.name']);
            foreach ($opposing as $row) {
                $other[(int) $row->transaction_journal_id] ??= (string) $row->name;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            if (array_key_exists($id, $result)) {
                continue; // a split journal touching the account twice: keep the first leg
            }
            $result[$id] = [
                'id'                 => $id,
                'group_id'           => (int) $row->transaction_group_id,
                'date'               => Carbon::parse($row->date, config('app.timezone')),
                'amount'             => bcadd(Steam::bcround((string) $row->amount, 2), '0', 2),
                'description'        => (string) $row->description,
                'opposing'           => $other[$id] ?? '',
                'internal_reference' => $meta[$id]['internal_reference'] ?? '',
                'external_id'        => $meta[$id]['external_id'] ?? '',
                'type'               => (string) $row->type,
            ];
        }

        return $result;
    }

    /**
     * A journal that is a bank charge for this line: created from the same statement row, or booked against
     * the bank itself (its opposing account has the bank's name).
     */
    private function isBankChargeJournal(array $journal, string $lineHash): bool
    {
        if (self::externalIdFor($lineHash) === $journal['external_id']) {
            return true;
        }

        return null !== $this->bankName && '' !== trim($this->bankName)
            && 0 === strcasecmp(trim($journal['opposing']), trim($this->bankName));
    }

    /**
     * The relative difference of a proposal, in percent of the Firefly amount, signed (bcmath, 1 decimal).
     */
    public static function percentDifference(string $from, string $to): ?string
    {
        if (0 === bccomp($from, '0', 2)) {
            return null;
        }
        $percent = bcdiv(bcmul(bcsub(ltrim($to, '-'), ltrim($from, '-'), 4), '100', 4), ltrim($from, '-'), 4);
        $rounded = bcadd($percent, -1 === bccomp($percent, '0', 4) ? '-0.05' : '0.05', 1);

        return (1 === bccomp($rounded, '0', 1) ? '+' : '').$rounded;
    }

    public static function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // card numbers, "PURCH" and pure digits say nothing about the merchant
        $parts = array_filter($parts, static fn (string $t): bool => 'PURCH' !== $t && 1 !== preg_match('/^\d+$/', $t) && mb_strlen($t) > 1);

        return array_values(array_unique($parts));
    }

    public static function jaccard(array $a, array $b): float
    {
        if ([] === $a || [] === $b) {
            return 0.0;
        }

        return count(array_intersect($a, $b)) / count(array_unique([...$a, ...$b]));
    }

    private function score(StatementLine $line, array $journal, int $days): float
    {
        $score = 100.0 - 10.0 * $days; // dominant: one day outweighs every bonus together (max 9)
        if (null !== $line->cardRef && 'card..'.$line->cardRef === $journal['internal_reference']) {
            $score += 5.0;
        }
        $lineTokens = self::tokens($line->counterparty);
        $score     += 3.0 * max(self::jaccard($lineTokens, self::tokens($journal['description'])), self::jaccard($lineTokens, self::tokens($journal['opposing'])));
        if (str_starts_with($journal['external_id'], 'fnb-')) {
            $score += 1.0;
        }

        return round($score, 6);
    }

    /**
     * @param array<int, true> $unavailable
     *
     * @return null|array{journal_id: int, from: string, to: string, reason: string}
     */
    private function proposal(StatementLine $line, array $journals, array $unavailable): ?array
    {
        $best = null;
        foreach ($journals as $journal) {
            if (array_key_exists($journal['id'], $unavailable) || bccomp($journal['amount'], '0', 2) !== bccomp($line->amount, '0', 2)) {
                continue;
            }
            $days = abs((int) $line->date->copy()->startOfDay()->diffInDays($journal['date']->copy()->startOfDay(), false));
            if ($days > self::PROPOSAL_DAYS || 0 === bccomp($journal['amount'], $line->amount, 2)) {
                continue;
            }
            $sameCard = null !== $line->cardRef && 'card..'.$line->cardRef === $journal['internal_reference'];
            $overlap  = max(self::jaccard(self::tokens($line->counterparty), self::tokens($journal['description'])), self::jaccard(self::tokens($line->counterparty), self::tokens($journal['opposing'])));
            if (!$sameCard && $overlap < self::STRONG_OVERLAP) {
                continue;
            }
            $key      = [$days, -(int) $sameCard, -$overlap, $journal['id']];
            if (null === $best || $key < $best[0]) {
                $best = [$key, ['journal_id' => $journal['id'], 'from' => $journal['amount'], 'to' => bcadd($line->amount, '0', 2), 'reason' => $sameCard ? 'same card' : 'similar description']];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * @return list<array{journal_id: int, group_id: int, date: string, amount: string, description: string, kind: string}>
     */
    private function fireflyOnly(array $journals, array $taken, ParsedStatement $parsed): array
    {
        $start  = $parsed->periodStart->copy()->startOfDay();
        $end    = $parsed->periodEnd->copy()->endOfDay();
        $only   = array_values(array_filter($journals, static fn (array $j): bool => !array_key_exists($j['id'], $taken) && $j['date']->between($start, $end)));
        $kinds  = [];
        // reservation + reversal: opposite signs, same absolute amount and card, within 7 days
        foreach ($only as $a) {
            foreach ($only as $b) {
                if ($a['id'] >= $b['id'] || array_key_exists($a['id'], $kinds) || array_key_exists($b['id'], $kinds)) {
                    continue;
                }
                if ('' !== $a['internal_reference'] && $a['internal_reference'] === $b['internal_reference'] && 0 === bccomp(bcadd($a['amount'], $b['amount'], 2), '0', 2)
                    && 0 !== bccomp($a['amount'], '0', 2) && abs((int) $a['date']->diffInDays($b['date'], false)) <= self::REVERSAL_DAYS) {
                    $kinds[$a['id']] = 'reversed_pair';
                    $kinds[$b['id']] = 'reversed_pair';
                }
            }
        }
        $result = [];
        foreach ($only as $journal) {
            $kind     = $kinds[$journal['id']] ?? (abs((int) $journal['date']->copy()->startOfDay()->diffInDays($parsed->statementDate->copy()->startOfDay(), false)) <= self::PENDING_DAYS ? 'pending' : 'unexplained');
            $result[] = [
                'journal_id'  => $journal['id'],
                'group_id'    => $journal['group_id'],
                'date'        => $journal['date']->format('Y-m-d'),
                'amount'      => $journal['amount'],
                'description' => $journal['description'],
                'kind'        => $kind,
            ];
        }

        return $result;
    }
}
