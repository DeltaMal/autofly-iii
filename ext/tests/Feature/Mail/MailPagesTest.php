<?php

/*
 * MailPagesTest.php
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

namespace FireflyIII\Ext\Tests\Feature\Mail;

use Carbon\Carbon;
use FireflyIII\Ext\Http\Controllers\AccountIdentifierController;
use FireflyIII\Ext\Http\Controllers\BankController;
use FireflyIII\Ext\Http\Controllers\GoogleController;
use FireflyIII\Ext\Http\Controllers\MailboxController;
use FireflyIII\Ext\Http\Controllers\MailController;
use FireflyIII\Ext\Mail\Gmail\GmailOAuth;
use FireflyIII\Ext\Mail\MailImporter;
use FireflyIII\Ext\Mail\MailProvider;
use FireflyIII\Ext\Models\AccountIdentifier;
use FireflyIII\Ext\Models\BankProfile;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Models\MailMessage;
use FireflyIII\Ext\Models\MailTransaction;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use FireflyIII\Repositories\User\UserRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The email import UI (ext/docs/03 "UI") over HTTP.
 *
 * @internal
 */
#[CoversClass(MailboxController::class)]
#[CoversClass(MailController::class)]
#[CoversClass(BankController::class)]
#[CoversClass(AccountIdentifierController::class)]
#[CoversClass(GoogleController::class)]
final class MailPagesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function testMailboxCrudShadowToLiveAndPollNow(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->get(route('ext.mailboxes.index'))->assertOk()->assertSee('No mailboxes yet.')->assertSee('Google OAuth client');
        $this->get(route('ext.mailboxes.create'))->assertOk();

        $this->post(route('ext.mailboxes.store'), ['name' => 'bad name!', 'banks' => ['fnb'], 'lookback_days' => 30, 'label_imported' => 'a', 'label_unmatched' => 'b', 'mode' => 'shadow'])->assertSessionHasErrors('name');
        $this->post(route('ext.mailboxes.store'), ['name' => 'personal', 'banks' => ['fnb'], 'query' => '', 'lookback_days' => 30, 'label_imported' => 'Firefly/Imported', 'label_unmatched' => 'Firefly/Unmatched', 'mode' => 'shadow', 'enabled' => '1'])
            ->assertRedirect(route('ext.mailboxes.index'));
        $mailbox = Mailbox::query()->firstOrFail();
        self::assertSame(['fnb'], $mailbox->banks);
        self::assertTrue($mailbox->isShadow());

        // shadow rows exist, then the mailbox goes live: they are cleared
        $message = MailMessage::create(['user_group_id' => $user->user_group_id, 'mailbox_id' => $mailbox->id, 'provider_message_id' => 'x', 'outcome' => 'transactions', 'status' => 'shadow_would_create']);
        $tx      = MailTransaction::create(['user_group_id' => $user->user_group_id, 'external_id' => 'fnb-x', 'status' => 'shadow_would_create', 'payload' => ['a' => 1]]);
        \Illuminate\Support\Facades\DB::table('ext_mail_message_transactions')->insert(['mail_message_id' => $message->id, 'mail_transaction_id' => $tx->id]);
        $this->get(route('ext.mailboxes.edit', [$mailbox->id]))->assertOk()->assertSee('personal');
        $this->post(route('ext.mailboxes.update', [$mailbox->id]), ['name' => 'personal', 'banks' => ['fnb'], 'lookback_days' => 10, 'label_imported' => 'Firefly/Imported', 'label_unmatched' => 'Firefly/Unmatched', 'mode' => 'live', 'enabled' => '1'])->assertRedirect();
        self::assertSame([0, 0], [MailMessage::count(), MailTransaction::count()]);
        self::assertSame(Mailbox::LIVE, $mailbox->refresh()->mode);
        self::assertSame(10, $mailbox->lookback_days);

        // poll now with a fake provider
        $provider = new FakeMailProvider([]);
        $this->app->instance(MailImporter::class, new MailImporter(app(\FireflyIII\Ext\Banks\BankRegistry::class), static fn (): MailProvider => $provider));
        $this->post(route('ext.mailboxes.poll', [$mailbox->id]))->assertRedirect()->assertSessionHas('success');
        self::assertNotNull($mailbox->refresh()->last_polled_at);

        // other users cannot touch it
        $this->actingAs($this->createUser('other@example.com'));
        $this->get(route('ext.mailboxes.edit', [$mailbox->id]))->assertNotFound();
        $this->post(route('ext.mailboxes.poll', [$mailbox->id]))->assertNotFound();
    }

    public function testOAuthConnectAndCallback(): void
    {
        $user    = $this->createUser();
        $this->actingAs($user);
        $mailbox = Mailbox::create(['user_group_id' => $user->user_group_id, 'user_id' => $user->id, 'name' => 'personal', 'banks' => ['fnb']]);

        // no client yet
        $this->get(route('ext.mailboxes.connect', [$mailbox->id]))->assertRedirect(route('ext.mailboxes.index'))->assertSessionHas('error');
        GmailOAuth::setGlobalClient('web-client', 'web-secret');

        $redirect = $this->get(route('ext.mailboxes.connect', [$mailbox->id]))->assertRedirect()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?client_id=web-client', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        self::assertSame(GmailOAuth::redirectUri(), $query['redirect_uri']);

        // wrong state is refused
        $this->get(route('ext.mail.oauth.callback', ['state' => 'nope', 'code' => 'c']))->assertRedirect()->assertSessionHas('error');

        $redirect = $this->get(route('ext.mailboxes.connect', [$mailbox->id]))->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $http     = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3599, 'scope' => GmailOAuth::SCOPE])),
            new Response(200, [], json_encode(['emailAddress' => 'owner@gmail.example'])),
        ]))]);
        $this->app->instance(GmailOAuth::class, new GmailOAuth($http));
        $this->app->bind(\FireflyIII\Ext\Mail\Gmail\GmailProvider::class, static fn ($app, array $p) => new \FireflyIII\Ext\Mail\Gmail\GmailProvider($p['mailbox'], $p['oauth'], $http));
        $this->get(route('ext.mail.oauth.callback', ['state' => $query['state'], 'code' => 'the-code']))->assertRedirect(route('ext.mailboxes.index'))->assertSessionHas('success');

        $mailbox->refresh();
        self::assertSame('rt', $mailbox->oauth['refresh_token']);
        self::assertSame('web-client', $mailbox->oauth['client_id']);
        self::assertSame('owner@gmail.example', $mailbox->email_address);
        self::assertTrue($mailbox->isConnected());
        // the state is single use
        $this->get(route('ext.mail.oauth.callback', ['state' => $query['state'], 'code' => 'again']))->assertSessionHas('error');
    }

    public function testActivityLogDetailAndRetry(): void
    {
        $user    = $this->createUser();
        $this->actingAs($user);
        $mailbox = Mailbox::create(['user_group_id' => $user->user_group_id, 'user_id' => $user->id, 'name' => 'personal', 'banks' => ['fnb'], 'mode' => 'live']);
        $message = MailMessage::create(['user_group_id' => $user->user_group_id, 'mailbox_id' => $mailbox->id, 'provider_message_id' => 'm1', 'received_at' => Carbon::now(), 'subject' => 'FNB :-) R10.00 paid', 'outcome' => 'transactions', 'status' => 'pending', 'bodies' => ['FNB :-) R10.00 paid from cheq a/c..379711 @ Smartapp. 4Oct 09:15']]);
        $golden  = array_column(json_decode((string) file_get_contents(__DIR__.'/../../fixtures/fnb-email/golden.json'), true)['messages'], null, 'name')['paid_from_ref']['expected']['transactions'][0];
        $tx      = MailTransaction::create(['user_group_id' => $user->user_group_id, 'external_id' => 'fnb-abc', 'status' => 'unmapped', 'error' => 'No active asset or liability account whose number starts with 379711.', 'payload' => ['external_id' => 'fnb-abc'] + $golden]);
        \Illuminate\Support\Facades\DB::table('ext_mail_message_transactions')->insert(['mail_message_id' => $message->id, 'mail_transaction_id' => $tx->id]);

        $this->get(route('ext.mail.index'))->assertOk()->assertSee('FNB :-) R10.00 paid');
        $this->get(route('ext.mail.index', ['status' => 'imported']))->assertOk()->assertSee('No emails yet.');
        $this->get(route('ext.mail.show', [$message->id]))->assertOk()->assertSee('fnb-abc')->assertSee('starts with 379711')->assertSee('Retry')->assertSee('4Oct 09:15');

        $this->app->instance(MailImporter::class, new MailImporter(app(\FireflyIII\Ext\Banks\BankRegistry::class), static fn (): MailProvider => new FakeMailProvider([])));
        $this->post(route('ext.mail.retry', [$message->id]))->assertRedirect(route('ext.mail.show', [$message->id]));
        self::assertSame(MailTransaction::UNMAPPED, $tx->refresh()->status, 'still no account, but it was retried');

        $this->actingAs($this->createUser('other@example.com'));
        $this->get(route('ext.mail.show', [$message->id]))->assertNotFound();
    }

    public function testBanksAccountsAndGoogleAdmin(): void
    {
        $user    = $this->createUser();
        $this->actingAs($user);
        $account = $this->createAccount($user, 'Cheque', 'asset', '1001001234');

        $this->get(route('ext.banks.index'))->assertOk()->assertSee('FNB');
        $this->post(route('ext.banks.update', ['fnb']), ['account_match' => 'suffix', 'default_account_id' => $account->id, 'tags' => 'fnb, email-import, fnb', 'require_authenticated' => '1', 'enabled' => '1'])->assertRedirect();
        $profile = BankProfile::query()->firstOrFail();
        self::assertSame(['suffix', $account->id, ['fnb', 'email-import'], true], [$profile->account_match, $profile->default_account_id, $profile->tags, $profile->require_authenticated]);
        $this->post(route('ext.banks.update', ['nope']), ['account_match' => 'prefix'])->assertNotFound();

        $this->get(route('ext.accounts.index'))->assertOk()->assertSee('1001001234');
        $this->post(route('ext.accounts.identifiers.store'), ['account_id' => $account->id, 'bank' => 'fnb', 'kind' => 'card', 'value' => '..7805'])->assertRedirect();
        $identifier = AccountIdentifier::query()->firstOrFail();
        self::assertSame(['card', '7805'], [$identifier->kind, $identifier->value]);
        $this->get(route('ext.accounts.index', ['bank' => 'fnb', 'test' => '1234']))->assertOk()->assertSee('resolves to Cheque');
        $this->post(route('ext.accounts.identifiers.destroy', [$identifier->id]))->assertRedirect();
        self::assertSame(0, AccountIdentifier::count());

        // the Google page is for the site owner only
        $this->get(route('ext.admin.google.index'))->assertRedirect(route('home'));
        app(UserRepositoryInterface::class)->attachRole($user, 'owner');
        $this->actingAs($user->fresh());
        $this->get(route('ext.admin.google.index'))->assertOk()->assertSee(GmailOAuth::redirectUri())->assertSee('In production');
        $this->post(route('ext.admin.google.update'), ['client_id' => 'cid', 'client_secret' => 'sec'])->assertRedirect();
        self::assertSame(['client_id' => 'cid', 'client_secret' => 'sec'], GmailOAuth::globalClient());
        // the menu shows every ext page, Google included for the owner
        $this->get(route('ext.periods.index'))->assertSee('Mailboxes')->assertSee('Email activity')->assertSee('Statements')->assertSee(route('ext.admin.google.index'), false);
    }
}
