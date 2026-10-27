<?php

/*
 * GmailProvider.php
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
use Closure;
use FireflyIII\Ext\Mail\MailMessage;
use FireflyIII\Ext\Mail\MailProvider;
use FireflyIII\Ext\Mail\MailProviderException;
use FireflyIII\Ext\Models\Mailbox;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Gmail over plain REST with Guzzle (no SDK, no new Composer packages). ext/docs/03 "Gmail REST".
 *
 * 401 -> refresh the token once and retry; a refused refresh -> MailProviderException(reconnectRequired);
 * 429/5xx and connection errors -> exponential backoff, 3 tries.
 */
final class GmailProvider implements MailProvider
{
    public const string API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /** @var null|array<string, string> lowercased label name => label id */
    private ?array $labels = null;

    /**
     * @param null|Closure(int): void $sleep milliseconds; replaced in tests
     */
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly GmailOAuth $oauth = new GmailOAuth(),
        private readonly ClientInterface $http = new Client(['timeout' => 60]),
        private readonly ?Closure $sleep = null,
    ) {}

    /**
     * Python's build_query(): from:(a OR b) or from:a, (extra query), after:<epoch seconds>, and
     * -label:<search name> for the imported label only.
     *
     * @param list<string> $senders
     */
    public static function buildQuery(array $senders, string $extra, Carbon $since, string $importedLabel): string
    {
        $parts   = [];
        $senders = array_values(array_unique($senders));
        if (1 === count($senders)) {
            $parts[] = 'from:'.$senders[0];
        } elseif (count($senders) > 1) {
            $parts[] = 'from:('.implode(' OR ', $senders).')';
        }
        if ('' !== trim($extra)) {
            $parts[] = '('.trim($extra).')';
        }
        $parts[] = 'after:'.$since->getTimestamp();
        if ('' !== $importedLabel) {
            $parts[] = '-label:'.self::labelSearchName($importedLabel);
        }

        return implode(' ', $parts);
    }

    /** "Firefly/Imported" -> "firefly-imported" */
    public static function labelSearchName(string $label): string
    {
        return str_replace(['/', ' '], '-', mb_strtolower($label, 'UTF-8'));
    }

    public function profile(): string
    {
        return (string) ($this->json('GET', '/profile')['emailAddress'] ?? '');
    }

    public function search(string $query): array
    {
        $ids   = [];
        $token = null;
        do {
            $data  = $this->json('GET', '/messages', ['query' => array_filter(['q' => $query, 'maxResults' => 500, 'pageToken' => $token], static fn ($v): bool => null !== $v)]);
            foreach ((array) ($data['messages'] ?? []) as $message) {
                $ids[] = (string) $message['id'];
            }
            $token = $data['nextPageToken'] ?? null;
        } while (null !== $token && '' !== $token);

        return $ids;
    }

    public function fetch(string $id): MailMessage
    {
        $data    = $this->json('GET', '/messages/'.rawurlencode($id), ['query' => ['format' => 'full']]);

        return self::decode($data, (string) $this->mailbox->name, fn (string $attachmentId): string => self::base64url((string) ($this->json('GET', sprintf('/messages/%s/attachments/%s', rawurlencode($id), rawurlencode($attachmentId)))['data'] ?? '')));
    }

    /**
     * Turns a format=full message into a MailMessage, like the Python importer's raw-message parsing:
     * header order kept, the first non-attachment text/html and text/plain parts (preferring those inside
     * multipart/alternative), charset converted to UTF-8, MIME-encoded subjects decoded.
     *
     * @param Closure(string): string $attachment fetches a part body by attachmentId
     */
    public static function decode(array $data, string $mailboxName, Closure $attachment): MailMessage
    {
        $payload = (array) ($data['payload'] ?? []);
        $from    = '';
        $subject = '';
        $auth    = [];
        foreach ((array) ($payload['headers'] ?? []) as $header) {
            $name = strtolower((string) ($header['name'] ?? ''));
            $value = (string) ($header['value'] ?? '');
            match ($name) {
                'from'                   => $from = '' === $from ? $value : $from,
                'subject'                => $subject = '' === $subject ? $value : $subject,
                'authentication-results' => $auth[] = $value,
                default                  => null,
            };
        }
        $html    = self::findPart($payload, 'text/html', true) ?? self::findPart($payload, 'text/html', false);
        $plain   = self::findPart($payload, 'text/plain', true) ?? self::findPart($payload, 'text/plain', false);
        $bodies  = MailMessage::bodiesFromParts(null === $html ? null : self::partText($html, $attachment), null === $plain ? null : self::partText($plain, $attachment));

        return new MailMessage(
            $mailboxName,
            (string) ($data['id'] ?? ''),
            Carbon::createFromTimestampMs((int) ($data['internalDate'] ?? 0), 'UTC'),
            MailMessage::address(self::decodeHeader($from)),
            \FireflyIII\Ext\Mail\Text::normaliseLine(self::decodeHeader($subject)),
            $auth,
            $bodies,
        );
    }

    public function fetchRaw(string $id): string
    {
        return self::base64url((string) ($this->json('GET', '/messages/'.rawurlencode($id), ['query' => ['format' => 'raw']])['raw'] ?? ''));
    }

    public function modifyLabels(array $ids, array $add, array $remove): void
    {
        if ([] === $ids || ([] === $add && [] === $remove)) {
            return;
        }
        $addIds    = array_map(fn (string $name): string => $this->labelId($name, true) ?? '', $add);
        $removeIds = array_values(array_filter(array_map(fn (string $name): ?string => $this->labelId($name, false), $remove)));
        $addIds    = array_values(array_filter($addIds));
        if ([] === $addIds && [] === $removeIds) {
            return;
        }
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $this->json('POST', '/messages/batchModify', ['json' => ['ids' => $chunk, 'addLabelIds' => $addIds, 'removeLabelIds' => $removeIds]]);
        }
    }

    private function labelId(string $name, bool $create): ?string
    {
        if (null === $this->labels) {
            $this->labels = [];
            foreach ((array) ($this->json('GET', '/labels')['labels'] ?? []) as $label) {
                $this->labels[mb_strtolower((string) $label['name'], 'UTF-8')] = (string) $label['id'];
            }
        }
        $key = mb_strtolower($name, 'UTF-8');
        if (!array_key_exists($key, $this->labels) && $create) {
            $created            = $this->json('POST', '/labels', ['json' => ['name' => $name, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show']]);
            $this->labels[$key] = (string) $created['id'];
        }

        return $this->labels[$key] ?? null;
    }

    /**
     * One API call with auth, a single refresh on 401 and backoff on 429/5xx.
     */
    private function json(string $method, string $path, array $options = []): array
    {
        $refreshed = false;
        $attempt   = 0;
        while (true) {
            ++$attempt;
            $response = null;
            try {
                $response = $this->http->request($method, self::API.$path, array_merge($options, [
                    'headers'     => ['Authorization' => 'Bearer '.$this->accessToken(), 'Accept' => 'application/json'],
                    'http_errors' => false,
                ]));
            } catch (ConnectException $e) {
                if ($attempt >= 3) {
                    throw new MailProviderException('Gmail is unreachable: '.$e->getMessage(), false, $e);
                }
                $this->backoff($attempt);

                continue;
            } catch (GuzzleException $e) {
                throw new MailProviderException('Gmail request failed: '.$e->getMessage(), false, $e);
            }
            $status   = $response->getStatusCode();
            if (401 === $status && !$refreshed) {
                $refreshed = true;
                $this->oauth->refresh($this->mailbox);

                continue;
            }
            if ((429 === $status || $status >= 500) && $attempt < 3) {
                $this->backoff($attempt);

                continue;
            }
            if ($status >= 400) {
                throw new MailProviderException(sprintf('Gmail %s %s failed with HTTP %d: %s', $method, $path, $status, self::errorText($response)), 401 === $status);
            }

            return (array) json_decode((string) $response->getBody(), true);
        }
    }

    private function accessToken(): string
    {
        $oauth = (array) $this->mailbox->oauth;
        if ('' === (string) ($oauth['access_token'] ?? '') || (int) ($oauth['expires_at'] ?? 0) <= Carbon::now()->getTimestamp()) {
            return $this->oauth->refresh($this->mailbox);
        }

        return (string) $oauth['access_token'];
    }

    private function backoff(int $attempt): void
    {
        $ms = 500 * (2 ** ($attempt - 1));
        if (null !== $this->sleep) {
            ($this->sleep)($ms);

            return;
        }
        usleep($ms * 1000);
    }

    private static function errorText(ResponseInterface $response): string
    {
        $data = json_decode((string) $response->getBody(), true);

        return mb_substr((string) ($data['error']['message'] ?? $data['error'] ?? $response->getReasonPhrase()), 0, 300);
    }

    /**
     * Depth-first search for the first non-attachment part of $mime; with $inAlternative only parts whose
     * parent is multipart/alternative count.
     */
    private static function findPart(array $part, string $mime, bool $inAlternative, bool $parentIsAlternative = false): ?array
    {
        $type = strtolower((string) ($part['mimeType'] ?? ''));
        if ($type === $mime && !self::isAttachment($part) && (!$inAlternative || $parentIsAlternative)) {
            return $part;
        }
        foreach ((array) ($part['parts'] ?? []) as $child) {
            $found = self::findPart((array) $child, $mime, $inAlternative, 'multipart/alternative' === $type);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function isAttachment(array $part): bool
    {
        if ('' !== (string) ($part['filename'] ?? '')) {
            return true;
        }
        foreach ((array) ($part['headers'] ?? []) as $header) {
            if ('content-disposition' === strtolower((string) ($header['name'] ?? '')) && str_starts_with(strtolower(trim((string) $header['value'])), 'attachment')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Closure(string): string $attachment
     */
    private static function partText(array $part, Closure $attachment): string
    {
        $body    = (array) ($part['body'] ?? []);
        $bytes   = array_key_exists('data', $body) ? self::base64url((string) $body['data']) : ('' !== (string) ($body['attachmentId'] ?? '') ? $attachment((string) $body['attachmentId']) : '');
        $charset = 'UTF-8';
        foreach ((array) ($part['headers'] ?? []) as $header) {
            if ('content-type' === strtolower((string) ($header['name'] ?? '')) && 1 === preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', (string) $header['value'], $match)) {
                $charset = $match[1];
            }
        }
        if ('utf-8' !== strtolower($charset) && 'utf8' !== strtolower($charset)) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
            $bytes     = false === $converted ? $bytes : $converted;
        }

        return mb_scrub($bytes, 'UTF-8');
    }

    public static function base64url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), false);
    }

    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return false === $decoded ? mb_decode_mimeheader($value) : $decoded;
    }
}
