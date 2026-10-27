<?php

/*
 * statements.php
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

return [
    'title'               => 'Statements',
    'upload'              => 'Upload a statement',
    'no_statements'       => 'No statements uploaded yet.',
    'uploaded'            => 'Uploaded',
    'account'             => 'Account',
    'period'              => 'Period',
    'status'              => 'Status',
    'status_parsed'       => 'To review',
    'status_committed'    => 'Committed',
    'counts'              => 'Lines',
    'difference'          => 'Difference',
    'file'                => 'Statement (PDF)',
    'file_help'           => 'FNB "Transaction History" PDF, at most :kb KB.',
    'account_auto'        => '(detect from the statement)',
    'account_help'        => 'Leave empty to detect the account from the statement\'s account number.',
    'password'            => 'PDF password',
    'password_help'       => 'Only if the PDF is protected. It is used once and never stored.',
    'options'             => 'Options',
    'opt_import_missing'  => 'Import missing transactions',
    'opt_reconcile'       => 'Mark matched transactions as reconciled',
    'opt_adjust'          => 'Book an adjustment for the unexplained difference',
    'opt_apply_rules'     => 'Apply rules to new transactions',
    'opt_auto_commit'     => 'Commit without review (only when the statement is clean)',
    'reprocess'           => 'Re-process (upload this exact file again)',
    'choose_account'      => 'Choose the account: :problem',
    'parsed'              => 'The statement was read. Review it and commit.',
    'auto_committed'      => 'The statement was committed automatically.',
    'committed'           => 'The statement was committed.',
    'breakdown'           => 'Balance breakdown',
    'b_closing'           => 'Statement closing balance',
    'b_firefly'           => 'Firefly balance at the end of the statement date',
    'b_pending'           => '− pending Firefly-only transactions (not yet posted)',
    'b_missing'           => '+ missing lines (to be created)',
    'b_proposals'         => '+ proposed amount changes',
    'b_expected'          => '= expected balance',
    'b_unexplained'       => 'Unexplained difference',
    'b_available'         => 'Available balance (bank)',
    'chain_invalid'       => 'The balance chain of this statement is broken. Check it before committing.',
    'tab_missing'         => 'Missing',
    'tab_matched'         => 'Matched',
    'tab_proposals'       => 'Proposals',
    'tab_firefly_only'    => 'Firefly only',
    'tab_warnings'        => 'Warnings',
    'date'                => 'Date',
    'description'         => 'Description',
    'amount'              => 'Amount',
    'balance'             => 'Balance',
    'create'              => 'Create',
    'ignore'              => 'Ignore',
    'apply'               => 'Apply',
    'transaction'         => 'Transaction',
    'score'               => 'Score',
    'carried'             => 'linked earlier',
    'proposal_text'       => 'Update the amount from :from to :to (:reason)',
    'kind_pending'        => 'Pending (not posted yet)',
    'kind_reversed_pair'  => 'Reserved and reversed (nets to zero)',
    'kind_unexplained'    => 'Unexplained',
    'nothing'             => 'Nothing here.',
    'commit'              => 'Commit',
    'result'              => 'Result',
    'result_created'      => ':count transactions created',
    'result_reconciled'   => ':count transactions marked as reconciled',
    'result_adjustment'   => 'An adjustment was booked',
    'view_created'        => 'View the created transactions',
    'view_reconcile'      => 'Reconcile view of the account',
    'status_line_matched' => 'matched',
    'status_line_created' => 'created',
];
