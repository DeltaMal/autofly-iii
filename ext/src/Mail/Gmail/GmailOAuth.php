<?php

/*
 * GmailOAuth.php
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

namespace FireflyIII\Ext\Mail\Gmail;

use Carbon\Carbon;
use FireflyIII\Ext\Mail\MailProviderException;
use FireflyIII\Ext\Models\Mailbox;
use FireflyIII\Support\Facades\AppConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Google OAuth for Gmail, inside Firefly (ext/docs/03 "OAuth inside Firefly"). The site owner's Web client lives
 * in AppConfiguration (upstream's FireflyConfig; ext_google_client_id / ext_google_client_secret, the secret Crypt-encrypted); a mailbox's own
 * client (e.g. a Desktop client imported from the Python importer) takes precedence.
 */
final class GmailOAuth
{
    public const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const string TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    public const string SCOPE         = 'https://www.googleapis.com/auth/gmail.modify';

    public function __construct(private readonly ClientInterface $http = new Client(['timeout' => 30])) {}

    /**
     * @return array{client_id: string, client_secret: null|string}
     */
    public static function globalClient(): array
    {
        $id     = (string) AppConfiguration::get('ext_google_client_id', '')->data;
        $secret = (string) AppConfiguration::get('ext_google_client_secret', '')->data;
        try {
            $secret = '' === $secret ? null : Crypt::decrypt($secret);
        } catch (Throwable) {
            $secret = null;
        }

        return ['client_id' => $id, 'client_secret' => $secret];
    }

    public static function setGlobalClient(string $clientId, ?string $clientSecret): void
    {
        AppConfiguration::set('ext_google_client_id', $clientId);
        if (null !== $clientSecret && '' !== $clientSecret) {
            AppConfiguration::set('ext_google_client_secret', Crypt::encrypt($clientSecret));
        }
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/ext/mail/oauth/callback';
    }

    /** The mailbox's own client when it has one, else the global one. */
    public static function clientFor(Mailbox $mailbox): array
    {
        $oauth = (array) $mailbox->oauth;
        if ('' !== (string) ($oauth['client_id'] ?? '')) {
            return ['client_id' => (string) $oauth['client_id'], 'client_secret' => $oauth['client_secret'] ?? null];
        }

        return self::globalClient();
    }

    public function authorizeUrl(string $clientId, string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges an authorisation code for tokens, in the shape stored on ext_mailboxes.oauth.
     */
    public function exchange(string $code, string $clientId, ?string $clientSecret): array
    {
        $data = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $clientId, 'client_secret' => $clientSecret, 'redirect_uri' => self::redirectUri()]);

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'access_token'  => (string) ($data['access_token'] ?? ''),
            'expires_at'    => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600) - 60)->getTimestamp(),
            'scope'         => (string) ($data['scope'] ?? self::SCOPE),
        ];
    }

    /**
     * Refreshes the access token and saves it on the mailbox. invalid_grant -> MailProviderException with
     * reconnectRequired (the caller marks the mailbox "reconnect required").
     */
    public function refresh(Mailbox $mailbox): string
    {
        $oauth  = (array) $mailbox->oauth;
        $client = self::clientFor($mailbox);
        if ('' === (string) ($oauth['refresh_token'] ?? '')) {
            throw new MailProviderException('The mailbox is not connected.', true);
        }
        $data   = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $oauth['refresh_token'], 'client_id' => $client['client_id'], 'client_secret' => $client['client_secret']]);
        $oauth['access_token'] = (string) ($data['access_token'] ?? '');
        $oauth['expires_at']   = Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600) - 60)->getTimestamp();
        if ('' !== (string) ($data['refresh_token'] ?? '')) {
            $oauth['refresh_token'] = (string) $data['refresh_token'];
        }
        $mailbox->oauth = $oauth;
        $mailbox->save();

        return $oauth['access_token'];
    }

    private function token(array $form): array
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['form_params' => array_filter($form, static fn ($v): bool => null !== $v && '' !== $v), 'http_errors' => true]);
        } catch (RequestException $e) {
            $body  = null !== $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
            $error = (string) (json_decode($body, true)['error'] ?? '');
            if ('invalid_grant' === $error) {
                throw new MailProviderException('Google refused the refresh token (invalid_grant): reconnect the mailbox.', true, $e);
            }

            throw new MailProviderException(sprintf('Google token request failed (%s).', '' !== $error ? $error : $e->getMessage()), false, $e);
        } catch (GuzzleException $e) {
            throw new MailProviderException('Google token request failed: '.$e->getMessage(), false, $e);
        }

        return (array) json_decode((string) $response->getBody(), true);
    }
}
