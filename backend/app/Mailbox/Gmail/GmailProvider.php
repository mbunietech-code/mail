<?php

namespace App\Mailbox\Gmail;

use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\Address;
use App\Mailbox\Data\AttachmentInfo;
use App\Mailbox\Data\Capabilities;
use App\Mailbox\Data\ChangeSet;
use App\Mailbox\Data\FolderInfo;
use App\Mailbox\Data\FolderStatus;
use App\Mailbox\Data\MessageContent;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageFlags;
use App\Mailbox\Data\MessageRef;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\PartDecoder;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Models\EmailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Gmail via the official REST API (never IMAP with a password). Labels are exposed as
 * folders; "permanent delete" is not offered because gmail.modify cannot do it and
 * Gmail empties Trash itself after 30 days (FR-ACCT-006).
 */
class GmailProvider implements MailProvider
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const SYSTEM_LABELS = [
        'INBOX' => 'inbox', 'SENT' => 'sent', 'DRAFT' => 'drafts', 'TRASH' => 'trash', 'SPAM' => 'spam',
    ];

    private const HIDDEN_LABELS = ['UNREAD', 'STARRED', 'IMPORTANT', 'CHAT'];

    private const METADATA_HEADERS = ['From', 'To', 'Cc', 'Reply-To', 'Subject', 'Date', 'Message-ID', 'In-Reply-To', 'References', 'Authentication-Results'];

    public function __construct(
        private readonly EmailAccount $account,
        private readonly GoogleTokenManager $tokens,
    ) {}

    public function capabilities(): Capabilities
    {
        return new Capabilities(move: true, labels: true, folderManagement: true, serverSearch: true);
    }

    public function verify(): void
    {
        $this->get('/profile');
    }

    public function listFolders(): array
    {
        $folders = [];
        foreach ($this->get('/labels')->json('labels', []) as $label) {
            $id = (string) $label['id'];
            if (in_array($id, self::HIDDEN_LABELS, true) || str_starts_with($id, 'CATEGORY_')) {
                continue;
            }
            $folders[] = new FolderInfo(
                path: $id,
                name: (string) ($label['name'] ?? $id),
                role: self::SYSTEM_LABELS[$id] ?? 'custom',
                delimiter: '/',
            );
        }

        return $folders;
    }

    public function folderStatus(string $folder): FolderStatus
    {
        $label = $this->get('/labels/'.rawurlencode($folder))->json();

        return new FolderStatus(0, 0, (int) ($label['messagesTotal'] ?? 0), (int) ($label['messagesUnread'] ?? 0));
    }

    public function fetchChanges(string $folder, SyncCursor $cursor, int $limit, ?\DateTimeInterface $windowStart = null, ?array $knownUids = null): ChangeSet
    {
        $status = $this->folderStatus($folder);
        $historyId = (string) $this->get('/profile')->json('historyId');

        if ($cursor->historyId === null) {
            $query = ['labelIds' => $folder, 'maxResults' => min($limit, 500)];
            if ($windowStart !== null) {
                $query['q'] = 'after:'.$windowStart->getTimestamp();
            }
            $ids = array_column($this->get('/messages', $query)->json('messages', []), 'id');

            return new ChangeSet($status, new SyncCursor(historyId: $historyId), false, $this->envelopes($ids), [], null, false);
        }

        try {
            $history = $this->get('/history', [
                'startHistoryId' => $cursor->historyId,
                'labelId' => $folder,
                'maxResults' => min($limit, 500),
            ]);
        } catch (ProviderError $e) {
            if ($e->errorCode === ProviderErrorCode::MessageGone) {
                // History too old: equivalent of UIDVALIDITY change — full window resync.
                return new ChangeSet($status, new SyncCursor, true, [], [], null, true);
            }
            throw $e;
        }

        $added = [];
        $changed = [];
        foreach ($history->json('history', []) as $entry) {
            foreach ($entry['messagesAdded'] ?? [] as $item) {
                $added[$item['message']['id']] = true;
            }
            foreach ([...($entry['labelsAdded'] ?? []), ...($entry['labelsRemoved'] ?? [])] as $item) {
                $changed[$item['message']['id']] = true;
            }
        }

        $next = (string) ($history->json('historyId') ?: $historyId);
        $hasMore = $history->json('nextPageToken') !== null;

        // Flag changes for known messages are reported as fresh envelopes; the sync
        // engine upserts by provider id.
        $ids = array_keys($added + $changed);

        return new ChangeSet($status, new SyncCursor(historyId: $next), false, $this->envelopes($ids), [], null, $hasMore);
    }

    /**
     * @param  list<string>  $ids
     * @return list<MessageEnvelope>
     */
    private function envelopes(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            try {
                $message = $this->get('/messages/'.rawurlencode($id), ['format' => 'metadata', 'metadataHeaders' => self::METADATA_HEADERS])->json();
            } catch (ProviderError $e) {
                if ($e->errorCode === ProviderErrorCode::MessageGone) {
                    continue; // deleted between listing and fetching
                }
                throw $e;
            }
            $out[] = $this->envelope($message);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<AttachmentInfo>  $attachments
     */
    private function envelope(array $message, array $attachments = []): MessageEnvelope
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $h) {
            $headers[strtolower((string) $h['name'])] ??= (string) $h['value'];
        }
        $labels = $message['labelIds'] ?? [];
        $date = isset($headers['date']) ? rescue(fn () => CarbonImmutable::parse($headers['date'])->utc(), null, false) : null;

        return new MessageEnvelope(
            uid: 0,
            providerId: (string) $message['id'],
            messageId: self::bareId($headers['message-id'] ?? null),
            inReplyTo: self::bareId($headers['in-reply-to'] ?? null),
            references: preg_match_all('/<([^<>\s]+)>/', $headers['references'] ?? '', $m) ? array_slice($m[1], -50) : [],
            from: self::addresses($headers['from'] ?? '')[0] ?? null,
            to: self::addresses($headers['to'] ?? ''),
            cc: self::addresses($headers['cc'] ?? ''),
            replyTo: self::addresses($headers['reply-to'] ?? ''),
            subject: mb_substr($headers['subject'] ?? '', 0, 998),
            sentAt: $date,
            internalDate: CarbonImmutable::createFromTimestampMsUTC((int) ($message['internalDate'] ?? 0)),
            size: (int) ($message['sizeEstimate'] ?? 0),
            flags: new MessageFlags(
                seen: ! in_array('UNREAD', $labels, true),
                flagged: in_array('STARRED', $labels, true),
                draft: in_array('DRAFT', $labels, true),
            ),
            attachments: $attachments,
            snippet: isset($message['snippet']) ? mb_substr(html_entity_decode((string) $message['snippet'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 200) : null,
            providerThreadId: isset($message['threadId']) ? (string) $message['threadId'] : null,
            authenticationResults: $headers['authentication-results'] ?? null,
        );
    }

    public function fetchMessage(MessageRef $ref): MessageContent
    {
        $message = $this->get('/messages/'.rawurlencode((string) $ref->providerId), ['format' => 'full'])->json();
        $text = null;
        $html = null;
        $attachments = [];

        $walk = function (array $part) use (&$walk, &$text, &$html, &$attachments) {
            $mime = strtolower((string) ($part['mimeType'] ?? ''));
            $filename = (string) ($part['filename'] ?? '');
            $body = $part['body'] ?? [];

            if ($filename !== '' || isset($body['attachmentId'])) {
                $contentId = null;
                $inline = false;
                foreach ($part['headers'] ?? [] as $h) {
                    $name = strtolower((string) $h['name']);
                    if ($name === 'content-id') {
                        $contentId = self::bareId((string) $h['value']);
                    }
                    if ($name === 'content-disposition' && str_starts_with(strtolower((string) $h['value']), 'inline')) {
                        $inline = true;
                    }
                }
                $attachments[] = new AttachmentInfo((string) ($part['partId'] ?? ''), PartDecoder::safeFilename($filename), $mime, (int) ($body['size'] ?? 0), $contentId, $inline && $contentId !== null);
            } elseif ($mime === 'text/plain' && $text === null && isset($body['data'])) {
                $text = self::b64url((string) $body['data']);
            } elseif ($mime === 'text/html' && $html === null && isset($body['data'])) {
                $html = self::b64url((string) $body['data']);
            }

            foreach ($part['parts'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($message['payload'] ?? []);

        return new MessageContent($this->envelope($message, $attachments), $text, $html, $attachments);
    }

    public function fetchAttachment(MessageRef $ref, string $partId): string
    {
        $content = $this->get('/messages/'.rawurlencode((string) $ref->providerId), ['format' => 'full'])->json();

        $find = function (array $part) use (&$find, $partId): ?array {
            if ((string) ($part['partId'] ?? '') === $partId) {
                return $part;
            }
            foreach ($part['parts'] ?? [] as $child) {
                if ($found = $find($child)) {
                    return $found;
                }
            }

            return null;
        };

        $part = $find($content['payload'] ?? []) ?? throw ProviderError::of(ProviderErrorCode::MessageGone, 'Attachment part not found.');
        if (isset($part['body']['data'])) {
            return self::b64url((string) $part['body']['data']);
        }

        $data = $this->get('/messages/'.rawurlencode((string) $ref->providerId).'/attachments/'.rawurlencode((string) ($part['body']['attachmentId'] ?? '')))->json('data');

        return self::b64url((string) $data);
    }

    public function setFlags(MessageRef $ref, ?bool $seen = null, ?bool $flagged = null): void
    {
        $add = [];
        $remove = [];
        if ($seen !== null) {
            $seen ? $remove[] = 'UNREAD' : $add[] = 'UNREAD';
        }
        if ($flagged !== null) {
            $flagged ? $add[] = 'STARRED' : $remove[] = 'STARRED';
        }
        $this->modify($ref, $add, $remove);
    }

    public function move(MessageRef $ref, string $toFolder): void
    {
        if ($toFolder === 'TRASH') {
            $this->post('/messages/'.rawurlencode((string) $ref->providerId).'/trash');

            return;
        }
        $this->modify($ref, [$toFolder], $ref->folder !== '' && $ref->folder !== $toFolder ? [$ref->folder] : []);
    }

    public function expunge(MessageRef $ref): void
    {
        throw ProviderError::of(ProviderErrorCode::NotSupported, 'Gmail empties Trash automatically after 30 days.');
    }

    public function search(string $folder, SearchCriteria $criteria, int $limit = 100): array
    {
        if ($criteria->isEmpty()) {
            return [];
        }
        $q = [];
        foreach (['from' => $criteria->from, 'to' => $criteria->to, 'subject' => $criteria->subject] as $op => $value) {
            if ($value !== null) {
                $q[] = $op.':"'.self::quote($value).'"';
            }
        }
        if ($criteria->text !== null) {
            $q[] = '"'.self::quote($criteria->text).'"';
        }
        if ($criteria->since !== null) {
            $q[] = 'after:'.$criteria->since->getTimestamp();
        }
        if ($criteria->before !== null) {
            $q[] = 'before:'.$criteria->before->getTimestamp();
        }
        if ($criteria->unseen !== null) {
            $q[] = $criteria->unseen ? 'is:unread' : '-is:unread';
        }
        if ($criteria->flagged !== null) {
            $q[] = $criteria->flagged ? 'is:starred' : '-is:starred';
        }

        return array_column($this->get('/messages', ['labelIds' => $folder, 'q' => implode(' ', $q), 'maxResults' => min($limit, 500)])->json('messages', []), 'id');
    }

    public function submit(OutgoingMessage $message, string $rfc822): void
    {
        // Gmail stores the sent copy itself. Any failure without a definitive response
        // is UnknownOutcome: the request may have been processed.
        $this->post('/messages/send', ['raw' => rtrim(strtr(base64_encode($rfc822), '+/', '-_'), '=')], sending: true);
    }

    public function appendToFolder(string $folder, string $rfc822, array $flags = []): void
    {
        $labels = [$folder];
        if (! in_array('\\Seen', $flags, true)) {
            $labels[] = 'UNREAD';
        }
        $this->post('/messages/insert', ['raw' => rtrim(strtr(base64_encode($rfc822), '+/', '-_'), '='), 'labelIds' => $labels]);
    }

    public function createFolder(string $path): void
    {
        if ($path === '' || mb_strlen($path) > 100 || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw ProviderError::of(ProviderErrorCode::NotSupported, 'Invalid label name.');
        }
        $this->post('/labels', ['name' => $path, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show']);
    }

    public function disconnect(): void {}

    // ---- HTTP ---------------------------------------------------------------------

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    private function modify(MessageRef $ref, array $add, array $remove): void
    {
        if ($add === [] && $remove === []) {
            return;
        }
        $this->post('/messages/'.rawurlencode((string) $ref->providerId).'/modify', ['addLabelIds' => $add, 'removeLabelIds' => $remove]);
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, $query);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body = [], bool $sending = false): Response
    {
        return $this->request('POST', $path, $body, $sending);
    }

    /** @param array<string, mixed> $data */
    private function request(string $method, string $path, array $data, bool $sending = false): Response
    {
        $send = fn (bool $refresh) => Http::withToken($this->tokens->accessToken($this->account, $refresh)->reveal())
            ->acceptJson()
            ->timeout(20)
            ->send($method, self::API.$path, $method === 'GET' ? ['query' => self::query($data)] : ['json' => $data === [] ? new \stdClass : $data]);

        try {
            $response = $send(false);
            if ($response->status() === 401) {
                $response = $send(true);
            }
        } catch (ConnectionException $e) {
            throw ProviderError::of($sending ? ProviderErrorCode::UnknownOutcome : ProviderErrorCode::Unreachable, 'Gmail API unreachable.', $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $code = match (true) {
            $response->status() === 401 => ProviderErrorCode::AuthFailed,
            $response->status() === 429, in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true) => ProviderErrorCode::Throttled,
            $response->status() === 403 => ProviderErrorCode::AuthFailed,
            $response->status() === 404 => ProviderErrorCode::MessageGone,
            $response->status() >= 500 => $sending ? ProviderErrorCode::UnknownOutcome : ProviderErrorCode::Unreachable,
            $sending => ProviderErrorCode::Rejected,
            default => ProviderErrorCode::ProtocolError,
        };

        $retryAfter = $response->header('Retry-After');

        throw new ProviderError($code, "Gmail API {$method} {$path} failed ({$response->status()} {$reason}).", null, is_numeric($retryAfter) ? (int) $retryAfter : null);
    }

    /**
     * Gmail expects repeated keys (metadataHeaders=From&metadataHeaders=To), not PHP arrays.
     *
     * @param  array<string, mixed>  $data
     */
    private static function query(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $v);
            }
        }

        return implode('&', $parts);
    }

    private static function b64url(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), false);
    }

    private static function quote(string $value): string
    {
        return str_replace(['\\', '"'], ['', ''], $value);
    }

    private static function bareId(?string $id): ?string
    {
        $id = $id === null ? '' : trim($id, " <>\t\r\n");

        return $id === '' ? null : mb_substr($id, 0, 255);
    }

    /** @return list<Address> */
    private static function addresses(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }
        $out = [];
        foreach (self::splitAddresses($header) as $chunk) {
            if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $chunk, $m)) {
                $out[] = new Address(strtolower(trim($m[2])), trim($m[1]) ?: null);
            } elseif (filter_var(trim($chunk), FILTER_VALIDATE_EMAIL)) {
                $out[] = new Address(strtolower(trim($chunk)));
            }
        }

        return $out;
    }

    /**
     * Splits an address-list header on commas outside quotes and angle brackets.
     *
     * @return list<string>
     */
    private static function splitAddresses(string $header): array
    {
        $parts = [];
        $buf = '';
        $quoted = false;
        $angle = 0;
        foreach (mb_str_split($header) as $ch) {
            if ($ch === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $ch === '<') {
                $angle++;
            } elseif (! $quoted && $ch === '>') {
                $angle = max(0, $angle - 1);
            }
            if ($ch === ',' && ! $quoted && $angle === 0) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }
}
