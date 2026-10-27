<?php

/*
 * mail.php
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
    'mailboxes'           => 'Mailboxes',
    'new_mailbox'         => 'New mailbox',
    'activity'            => 'Email activity',
    'banks'               => 'Banks',
    'accounts'            => 'Accounts',
    'google'              => 'Google',
    'name'                => 'Name',
    'name_help'           => 'Letters, digits, "-" and "_" (the Python importer\'s mailbox id). It appears in transaction notes.',
    'address'             => 'Address',
    'mode'                => 'Mode',
    'mode_live'           => 'Live',
    'mode_shadow'         => 'Shadow',
    'mode_help'           => 'Shadow mode parses and checks everything but never creates transactions or applies labels. Switching to live clears the shadow results so everything is looked at again.',
    'enabled'             => 'Enabled',
    'last_poll'           => 'Last poll',
    'last_error'          => 'Last error',
    'never'               => 'never',
    'connect'             => 'Connect',
    'reconnect'           => 'Reconnect',
    'poll_now'            => 'Poll now',
    'edit'                => 'Edit',
    'delete'              => 'Delete',
    'save'                => 'Save',
    'query'               => 'Extra Gmail search',
    'query_help'          => 'Optional, added in parentheses to the search.',
    'lookback_days'       => 'Look back (days)',
    'label_imported'      => 'Label for imported emails',
    'label_unmatched'     => 'Label for unmatched emails',
    'no_mailboxes'        => 'No mailboxes yet.',
    'no_client'           => 'The site owner has not set up a Google OAuth client yet (Bank integrations → Google).',
    'mailbox_saved'       => 'Mailbox ":name" saved.',
    'mailbox_deleted'     => 'Mailbox ":name" deleted.',
    'went_live'           => 'The mailbox is live now: its shadow results were cleared and the next poll looks at every email again.',
    'oauth_state'         => 'The Google sign-in could not be verified (state mismatch). Try connecting again.',
    'oauth_denied'        => 'Google did not grant access: :error',
    'no_refresh_token'    => 'Google returned no refresh token. Remove the app\'s access in your Google account and connect again.',
    'connected'           => 'Mailbox ":name" is connected to :email.',
    'received'            => 'Received',
    'sender'              => 'Sender',
    'subject'             => 'Subject',
    'mailbox'             => 'Mailbox',
    'status'              => 'Status',
    'outcome'             => 'Outcome',
    'all'                 => '(all)',
    'filter'              => 'Filter',
    'no_messages'         => 'No emails yet.',
    'bodies'              => 'Email text',
    'unmatched_segments'  => 'Unmatched text',
    'transactions'        => 'Transactions',
    'external_id'         => 'External id',
    'amount'              => 'Amount',
    'date'                => 'Date',
    'account'             => 'Account',
    'firefly'             => 'In Firefly',
    'error'               => 'Error',
    'retry'               => 'Retry',
    'bank'                => 'Bank',
    'account_match'       => 'Account number match',
    'match_prefix'        => 'Starts with the number in the email',
    'match_suffix'        => 'Ends with the number in the email',
    'default_account'     => 'Default account',
    'none'                => '(none)',
    'tags'                => 'Tags',
    'tags_help'           => 'Comma separated, added to every imported transaction.',
    'require_auth'        => 'Require a verified sender (DKIM/SPF/DMARC)',
    'bank_saved'          => 'Bank profile :bank saved.',
    'identifiers'         => 'Identifiers',
    'kind_account_number' => 'Account number',
    'kind_card'           => 'Card',
    'value'               => 'Digits',
    'add_identifier'      => 'Add identifier',
    'auto_number'         => 'Account number in Firefly',
    'test_match'          => 'Test a number from an email',
    'test'                => 'Test',
    'test_result_ok'      => ':value resolves to :account (:via).',
    'test_result_fail'    => ':value: :problem',
    'digits_only'         => 'The identifier must contain digits.',
    'identifier_saved'    => ':value now maps to :account.',
    'identifier_deleted'  => 'Identifier removed.',
    'client_id'           => 'Client id',
    'client_secret'       => 'Client secret',
    'client_secret_help'  => 'Stored encrypted. Leave empty to keep the current secret.',
    'redirect_uri'        => 'Authorised redirect URI',
    'google_help'         => 'Create an OAuth client of type "Web application" in the Google Cloud console, add the redirect URI below, and enable the Gmail API. The only scope used is gmail.modify (read and label; never delete).',
    'google_consent'      => 'Set the OAuth consent screen to "In production". In "Testing", Google expires refresh tokens after 7 days and every mailbox has to be reconnected.',
    'google_saved'        => 'The Google OAuth client was saved.',
];
