<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mailbox\Data\SearchCriteria;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * FR-MAIL-009. Structured filters run on cached metadata; body/subject text is searched
 * by the provider (subjects are encrypted at rest and bodies are not stored), and the
 * results are intersected with the cache. Messages outside the sync window are not
 * returned — a documented limitation.
 */
class SearchController extends Controller
{
    public function __construct(private readonly ProviderFactory $providers) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $text = ['nullable', 'string', 'max:200', 'not_regex:/[\x00\r\n]/'];
        $data = $request->validate([
            'q' => $text, 'from' => $text, 'to' => $text, 'subject' => $text,
            'since' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date_format:Y-m-d'],
            'has_attachment' => ['nullable', 'boolean'],
            'unread' => ['nullable', 'boolean'],
            'folder_id' => ['nullable', 'string', 'size:26'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (array_filter($data, fn ($v) => $v !== null && $v !== '') === []) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Provide at least one search criterion.');
        }

        /** @var User $user */
        $user = $request->user();
        $account = $user->usableEmailAccounts()->with('providerProfile')->findOrFail($id);
        $limit = (int) ($data['limit'] ?? 50);

        $query = $account->messages()->getQuery();
        if (! empty($data['folder_id'])) {
            $query->where('folder_id', Folder::query()->where('email_account_id', $account->id)->findOrFail((string) $data['folder_id'])->id);
        }
        if (! empty($data['from'])) {
            $query->where(fn ($q) => $q->where('from_email', 'like', '%'.self::like($data['from']).'%')->orWhere('from_name', 'like', '%'.self::like($data['from']).'%'));
        }
        if (! empty($data['to'])) {
            $query->where(fn ($q) => $q->where('to_json', 'like', '%'.self::like($data['to']).'%')->orWhere('cc_json', 'like', '%'.self::like($data['to']).'%'));
        }
        if (! empty($data['since'])) {
            $query->where('internal_date', '>=', $data['since']);
        }
        if (! empty($data['before'])) {
            $query->where('internal_date', '<', $data['before']);
        }
        if (isset($data['has_attachment'])) {
            $query->where('has_attachments', (bool) $data['has_attachment']);
        }
        if (isset($data['unread'])) {
            $query->where('is_seen', ! $data['unread']);
        }

        if (! empty($data['q']) || ! empty($data['subject'])) {
            $key = 'search:'.$user->id;
            if (RateLimiter::tooManyAttempts($key, 20)) {
                throw ApiException::tooManyAttempts(RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 60);

            $folder = ! empty($data['folder_id'])
                ? Folder::query()->where('email_account_id', $account->id)->findOrFail((string) $data['folder_id'])
                : $account->folders()->where('role', 'inbox')->firstOrFail();
            $ids = $this->providerSearch($account, $folder, new SearchCriteria(
                text: $data['q'] ?? null,
                subject: $data['subject'] ?? null,
                since: isset($data['since']) ? CarbonImmutable::parse($data['since']) : null,
                before: isset($data['before']) ? CarbonImmutable::parse($data['before']) : null,
            ));
            $query->where('folder_id', $folder->id);
            $account->providerProfile->driver === 'gmail'
                ? $query->whereIn('provider_message_id', $ids)
                : $query->whereIn('uid', $ids);
        }

        $rows = $query->orderByDesc('internal_date')->orderByDesc('id')->limit($limit)->get();

        return response()->json(['data' => $rows->map(MessageController::present(...))->values(), 'next_cursor' => null]);
    }

    /** @return list<int|string> */
    private function providerSearch(EmailAccount $account, Folder $folder, SearchCriteria $criteria): array
    {
        $provider = $this->providers->forAccount($account);
        try {
            return $provider->search($folder->remote_name, $criteria, 500);
        } catch (ProviderError $e) {
            throw ProviderErrorResponder::toApi($e);
        } finally {
            $provider->disconnect();
        }
    }

    private static function like(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
