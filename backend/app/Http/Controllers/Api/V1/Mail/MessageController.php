<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Models\User;
use App\Services\Mail\MessageService;
use App\Services\UserPreferences;
use App\Support\CompanyDomains;
use App\Support\Cursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly UserPreferences $preferences,
    ) {}

    /** GET /folders/{id}/messages — cached metadata, keyset-paginated (FR-MAIL-003). */
    public function index(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'in:date_desc,date_asc,from'],
            'unread' => ['nullable', 'boolean'],
            'starred' => ['nullable', 'boolean'],
        ]);
        $folder = $this->ownedFolder($request, $id);

        $query = $folder->messages()->getQuery();
        if ($request->boolean('unread')) {
            $query->where('is_seen', false);
        }
        if ($request->boolean('starred')) {
            $query->where('is_flagged', true);
        }

        return response()->json($this->paginate($query, $data['sort'] ?? 'date_desc', (int) ($data['limit'] ?? 50), $data['cursor'] ?? null));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $request->validate(['images' => ['nullable', 'in:block,allow']]);
        $message = $this->owned($request, $id);

        /** @var User $user */
        $user = $request->user();
        // Explicit per-message consent, or a sender the user chose to trust (FR-SET-002).
        $allowImages = $request->query('images') === 'allow' || $this->preferences->trustsImagesFrom($user, $message->from_email);

        return response()->json($this->messages->read($message, $allowImages));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['is_seen' => ['sometimes', 'boolean'], 'is_flagged' => ['sometimes', 'boolean']]);
        if ($data === []) {
            throw new ApiException('VALIDATION_FAILED', 422, 'Nothing to update.');
        }
        $message = $this->owned($request, $id);
        $this->messages->setFlags($message, $data['is_seen'] ?? null, $data['is_flagged'] ?? null);

        return response()->json(self::present($message->refresh()));
    }

    public function move(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['folder_id' => ['required', 'string', 'size:26']]);
        $message = $this->owned($request, $id);
        $this->messages->move($message, $this->ownedFolder($request, $data['folder_id']));

        return response()->json(null, 204);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->validate(['permanent' => ['nullable', 'boolean']]);
        $this->messages->trash($this->owned($request, $id), $request->boolean('permanent'));

        return response()->json(null, 204);
    }

    public function spam(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['spam' => ['required', 'boolean']]);
        $this->messages->markSpam($this->owned($request, $id), (bool) $data['spam']);

        return response()->json(null, 204);
    }

    /** FR-MAIL-011: up to 100 messages; each result reported separately. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'size:26', 'distinct'],
            'action' => ['required', 'in:seen,unseen,flag,unflag,move,trash'],
            'folder_id' => ['required_if:action,move', 'nullable', 'string', 'size:26'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $target = $data['action'] === 'move' ? $this->ownedFolder($request, (string) $data['folder_id']) : null;
        $found = MessageMetadata::query()->ownedBy($user)->with(['folder', 'emailAccount.providerProfile'])->whereIn('id', $data['ids'])->get()->keyBy('id');

        $results = [];
        foreach ($data['ids'] as $messageId) {
            $message = $found->get($messageId);
            if ($message === null) {
                $results[$messageId] = 'not_found';

                continue;
            }
            try {
                match ($data['action']) {
                    'seen' => $this->messages->setFlags($message, true, null),
                    'unseen' => $this->messages->setFlags($message, false, null),
                    'flag' => $this->messages->setFlags($message, null, true),
                    'unflag' => $this->messages->setFlags($message, null, false),
                    'move' => $this->messages->move($message, $target),
                    'trash' => $this->messages->trash($message, false),
                };
                $results[$messageId] = 'ok';
            } catch (ApiException $e) {
                $results[$messageId] = strtolower($e->errorCode);
            } catch (Throwable $e) {
                report($e);
                $results[$messageId] = 'error';
            }
        }

        return response()->json(['results' => $results]);
    }

    /** GET /threads/{key}?account_id= — the conversation across folders, oldest first. */
    public function thread(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['account_id' => ['required', 'string', 'size:26']]);
        $account = $this->ownedAccount($request, $data['account_id']);

        $messages = $account->messages()->where('thread_key', $key)->orderBy('internal_date')->orderBy('id')->limit(200)->get();
        abort_if($messages->isEmpty(), 404);

        return response()->json(['data' => $messages->map(self::present(...))->values(), 'next_cursor' => null]);
    }

    /**
     * @param  Builder<MessageMetadata>  $query
     * @return array{data: mixed, next_cursor: string|null}
     */
    private function paginate(Builder $query, string $sort, int $limit, ?string $cursor): array
    {
        [$column, $direction] = match ($sort) {
            'date_asc' => ['internal_date', 'asc'],
            'from' => ['from_email', 'asc'],
            default => ['internal_date', 'desc'],
        };
        $after = Cursor::decode($cursor);
        if ($after !== null) {
            $op = $direction === 'desc' ? '<' : '>';
            $query->where(fn ($q) => $q->where($column, $op, $after[0])
                ->orWhere(fn ($q) => $q->where($column, $after[0])->where('id', $op, $after[1])));
        }

        $rows = $query->orderBy($column, $direction)->orderBy('id', $direction)->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        return [
            'data' => $rows->map(self::present(...))->values(),
            'next_cursor' => $more && $last !== null
                ? Cursor::encode([$column === 'internal_date' ? $last->internal_date->format('Y-m-d H:i:s') : (string) $last->from_email, $last->id])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function present(MessageMetadata $m): array
    {

        return [
            'id' => $m->id,
            'account_id' => $m->email_account_id,
            'folder_id' => $m->folder_id,
            'thread_key' => $m->thread_key,
            'from' => ['email' => $m->from_email, 'name' => $m->from_name],
            'to' => $m->to_json ?? [],
            'subject' => $m->subject_enc ?? '',
            'snippet' => $m->snippet_enc,
            'date' => ($m->sent_at ?? $m->internal_date)->toIso8601String(),
            'is_seen' => $m->is_seen,
            'is_flagged' => $m->is_flagged,
            'is_answered' => $m->is_answered,
            'has_attachments' => $m->has_attachments,
            'is_external' => ! CompanyDomains::contains($m->from_email),
        ];
    }

    private function owned(Request $request, string $id): MessageMetadata
    {
        /** @var User $user */
        $user = $request->user();

        return MessageMetadata::query()->ownedBy($user)->with(['folder', 'emailAccount.providerProfile'])->findOrFail($id);
    }

    private function ownedFolder(Request $request, string $id): Folder
    {
        /** @var User $user */
        $user = $request->user();

        return Folder::query()->whereIn('email_account_id', $user->emailAccounts()->select('id'))->findOrFail($id);
    }

    private function ownedAccount(Request $request, string $id): EmailAccount
    {
        /** @var User $user */
        $user = $request->user();

        return $user->emailAccounts()->findOrFail($id);
    }
}
