<?php

/*
 * GmailProviderTest.php
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
use FireflyIII\Ext\Mail\Gmail\GmailOAuth;
use FireflyIII\Ext\Mail\Gmail\GmailProvider;
use FireflyIII\Ext\Mail\MailProviderException;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Ext\Tests\Feature\FeatureTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Gmail over REST with a Guzzle MockHandler (ext/docs/03 "Acceptance").
 *
 * @internal
 */
#[CoversClass(GmailProvider::class)]
#[CoversClass(GmailOAuth::class)]
final class GmailProviderTest extends FeatureTestCase
{
    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $requests = [];

    public function testQueryStringIsPythonsBuildQuery(): void
    {
        $since = Carbon::createFromTimestamp(1790812800);
        self::assertSame('from:inContact@fnb.co.za after:1790812800 -label:firefly-imported', GmailProvider::buildQuery(['inContact@fnb.co.za'], '', $since, 'Firefly/Imported'));
        self::assertSame('from:(a@x OR b@y) (has:nouserlabels) after:1790812800 -label:my-imported-mail', GmailProvider::buildQuery(['a@x', 'b@y', 'a@x'], ' has:nouserlabels ', $since, 'My Imported/Mail'));
    }

    public function testSearchFollowsPagination(): void
    {
        $provider = $this->provider([
            new Response(200, [], json_encode(['messages' => [['id' => 'a'], ['id' => 'b']], 'nextPageToken' => 'p2'])),
            new Response(200, [], json_encode(['messages' => [['id' => 'c']]])),
        ]);
        self::assertSame(['a', 'b', 'c'], $provider->search('from:x'));
        parse_str($this->requests[0]->getUri()->getQuery(), $first);
        parse_str($this->requests[1]->getUri()->getQuery(), $second);
        self::assertSame(['q' => 'from:x', 'maxResults' => '500'], $first);
        self::assertSame('p2', $second['pageToken']);
        self::assertSame('Bearer valid-token', $this->requests[0]->getHeaderLine('Authorization'));
    }

    public function testFormatFullDecodingMatchesTheGoldenBodies(): void
    {
        $golden   = array_column(json_decode((string) file_get_contents(__DIR__.'/../../fixtures/fnb-email/golden.json'), true)['messages'], null, 'name')['eml:paid_to_alternative.eml'];
        $b64      = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $message  = [
            'id'           => 'paid_to_alternative',
            'internalDate' => (string) (Carbon::parse($golden['message']['received_at'])->getTimestamp() * 1000),
            'payload'      => [
                'mimeType' => 'multipart/mixed',
                'headers'  => [
                    ['name' => 'Authentication-Results', 'value' => $golden['message']['auth_results'][0]],
                    ['name' => 'From', 'value' => 'FNB <inContact@fnb.co.za>'],
                    ['name' => 'Subject', 'value' => '=?UTF-8?B?'.base64_encode($golden['message']['subject']).'?='],
                    ['name' => 'Authentication-Results', 'value' => 'lower; dkim=pass header.i=@evil.example'],
                ],
                'parts'    => [
                    ['mimeType' => 'text/html', 'filename' => 'banner.html', 'body' => ['data' => $b64('<p>attachment, not the body</p>')]],
                    ['mimeType' => 'multipart/alternative', 'parts' => [
                        ['mimeType' => 'text/plain', 'headers' => [['name' => 'Content-Type', 'value' => 'text/plain; charset="utf-8"']], 'body' => ['data' => $b64($golden['parts']['plain'])]],
                        ['mimeType' => 'text/html', 'headers' => [['name' => 'Content-Type', 'value' => 'text/html; charset=utf-8']], 'body' => ['attachmentId' => 'att-1']],
                    ]],
                ],
            ],
        ];
        $provider = $this->provider([
            new Response(200, [], json_encode($message)),
            new Response(200, [], json_encode(['data' => $b64($golden['parts']['html'])])),
        ]);
        $fetched  = $provider->fetch('paid_to_alternative');

        self::assertSame($golden['message']['bodies'], $fetched->bodies);
        self::assertSame('incontact@fnb.co.za', $fetched->sender);
        self::assertSame($golden['message']['subject'], $fetched->subject);
        self::assertSame($golden['message']['auth_results'][0], $fetched->authResults[0]);
        self::assertCount(2, $fetched->authResults);
        self::assertSame($golden['message']['received_at'], $fetched->receivedAt->format('Y-m-d\TH:i:sP'));
        self::assertStringEndsWith('/attachments/att-1', $this->requests[1]->getUri()->getPath());
    }

    public function testNonUtf8CharsetIsConverted(): void
    {
        $latin1   = mb_convert_encoding('<p>FNB :-) R10.00 reserved for purchase @ Café Noir from FNB card a/c..110149 using card..7805. Avail R98. 4Oct 12:59</p>', 'ISO-8859-1', 'UTF-8');
        $message  = ['id' => 'x', 'internalDate' => '1791104371000', 'payload' => ['mimeType' => 'text/html', 'headers' => [['name' => 'Content-Type', 'value' => 'text/html; charset=ISO-8859-1'], ['name' => 'Subject', 'value' => '=?iso-8859-1?Q?Caf=E9?=']], 'body' => ['data' => rtrim(strtr(base64_encode($latin1), '+/', '-_'), '=')]]];
        $fetched  = $this->provider([new Response(200, [], json_encode($message))])->fetch('x');
        self::assertSame(['FNB :-) R10.00 reserved for purchase @ Café Noir from FNB card a/c..110149 using card..7805. Avail R98. 4Oct 12:59'], $fetched->bodies);
        self::assertSame('Café', $fetched->subject);
    }

    public function testLabelsAreCreatedWhenMissingAndSkippedWhenRemovedButMissing(): void
    {
        $provider = $this->provider([
            new Response(200, [], json_encode(['labels' => [['id' => 'L1', 'name' => 'INBOX'], ['id' => 'L2', 'name' => 'firefly/unmatched']]])),
            new Response(200, [], json_encode(['id' => 'L3', 'name' => 'Firefly/Imported'])),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);
        $provider->modifyLabels(['m1'], ['Firefly/Imported'], ['Firefly/Unmatched']);
        $provider->modifyLabels(['m2'], [], ['Does/Not/Exist']); // nothing to do: no request
        self::assertCount(3, $this->requests);
        self::assertSame(['name' => 'Firefly/Imported', 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show'], json_decode((string) $this->requests[1]->getBody(), true));
        self::assertSame(['ids' => ['m1'], 'addLabelIds' => ['L3'], 'removeLabelIds' => ['L2']], json_decode((string) $this->requests[2]->getBody(), true));
    }

    public function testBatchModifyIsChunkedAt1000(): void
    {
        $provider = $this->provider([
            new Response(200, [], json_encode(['labels' => [['id' => 'L1', 'name' => 'Firefly/Imported']]])),
            new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}'),
        ]);
        $provider->modifyLabels(array_map(static fn (int $i): string => 'm'.$i, range(1, 2500)), ['Firefly/Imported'], []);
        $sizes    = array_map(static fn ($r): int => count(json_decode((string) $r->getBody(), true)['ids']), array_slice($this->requests, 1));
        self::assertSame([1000, 1000, 500], $sizes);
    }

    public function testExpiredTokenIsRefreshedAndSavedAnd401RetriesOnce(): void
    {
        $mailbox  = $this->mailbox(['access_token' => 'old', 'expires_at' => Carbon::now()->subMinute()->getTimestamp()]);
        $provider = $this->provider([
            new Response(200, [], json_encode(['access_token' => 'fresh', 'expires_in' => 3599])), // refresh (expired)
            new Response(401, [], '{}'),                                                          // token revoked meanwhile
            new Response(200, [], json_encode(['access_token' => 'fresher', 'expires_in' => 3599])), // refresh once
            new Response(200, [], json_encode(['emailAddress' => 'owner@example.com'])),
        ], $mailbox);
        self::assertSame('owner@example.com', $provider->profile());
        self::assertSame('fresher', $mailbox->refresh()->oauth['access_token']);
        self::assertSame('refresh-token', $mailbox->oauth['refresh_token']);
        parse_str((string) $this->requests[0]->getBody(), $form);
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-token', 'client_id' => 'mailbox-client', 'client_secret' => 'mailbox-secret'], $form);
        self::assertSame('Bearer fresher', $this->requests[3]->getHeaderLine('Authorization'));
    }

    public function testInvalidGrantMeansReconnect(): void
    {
        $mailbox  = $this->mailbox(['access_token' => '', 'expires_at' => 0]);
        $provider = $this->provider([new Response(400, [], json_encode(['error' => 'invalid_grant']))], $mailbox);

        try {
            $provider->profile();
            self::fail('expected an exception');
        } catch (MailProviderException $e) {
            self::assertTrue($e->reconnectRequired);
        }
    }

    public function testRateLimitsBackOffThreeTries(): void
    {
        $slept    = [];
        $provider = $this->provider([new Response(429), new Response(503), new Response(200, [], json_encode(['emailAddress' => 'a@b']))], null, static function (int $ms) use (&$slept): void { $slept[] = $ms; });
        self::assertSame('a@b', $provider->profile());
        self::assertSame([500, 1000], $slept);

        $failing  = $this->provider([new Response(500), new Response(500), new Response(500)], null, static function (): void {});
        $this->expectException(MailProviderException::class);
        $failing->profile();
    }

    public function testAuthoriseUrlAndGlobalClient(): void
    {
        GmailOAuth::setGlobalClient('global-id', 'global-secret');
        self::assertSame(['client_id' => 'global-id', 'client_secret' => 'global-secret'], GmailOAuth::globalClient());
        self::assertNotSame('global-secret', \FireflyIII\Support\Facades\AppConfiguration::get('ext_google_client_secret')->data, 'the secret is stored encrypted');
        $url = (new GmailOAuth())->authorizeUrl('global-id', 'state-123');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?client_id=global-id&redirect_uri=', $url);
        self::assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fgmail.modify&access_type=offline&prompt=consent&state=state-123', $url);
        // a mailbox's own (imported Desktop) client wins over the global one
        self::assertSame('mailbox-client', GmailOAuth::clientFor($this->mailbox([]))['client_id']);
    }

    private function mailbox(array $oauth): Mailbox
    {
        $user = $this->createUser('gmail-'.uniqid().'@example.com');

        return Mailbox::create([
            'user_group_id' => $user->user_group_id, 'user_id' => $user->id, 'name' => 'personal-'.uniqid(), 'banks' => ['fnb'],
            'oauth' => array_merge(['client_id' => 'mailbox-client', 'client_secret' => 'mailbox-secret', 'refresh_token' => 'refresh-token', 'access_token' => 'valid-token', 'expires_at' => Carbon::now()->addHour()->getTimestamp(), 'scope' => GmailOAuth::SCOPE], $oauth),
        ]);
    }

    private function provider(array $responses, ?Mailbox $mailbox = null, ?\Closure $sleep = null): GmailProvider
    {
        $this->requests = [];
        $stack          = HandlerStack::create(new MockHandler($responses));
        $client         = new Client(['handler' => $stack]);
        $self           = $this;
        $stack->push(static function (callable $handler) use ($self) {
            return static function ($request, array $options) use ($handler, $self) {
                $self->requests[] = $request;

                return $handler($request, $options);
            };
        });

        return new GmailProvider($mailbox ?? $this->mailbox([]), new GmailOAuth($client), $client, $sleep ?? static function (): void {});
    }
}
