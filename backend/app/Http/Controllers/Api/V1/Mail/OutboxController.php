<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\OutboxAttachment;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Mail\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OutboxController extends Controller
{
    public function __construct(private readonly OutboxService $outbox) {}

    /** POST /outbox/send — Idempotency-Key required (FR-SEND-005). */
    public function send(Request $request): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 400, 'An Idempotency-Key header (8–80 characters: letters, digits, _ or -) is required.');
        }

        $address = ['required', 'email:rfc', 'max:254', 'not_regex:/[\r\n]/'];
        $data = $request->validate([
            'account_id' => ['required', 'string', 'size:26'],
            'to' => ['nullable', 'array', 'max:100'], 'to.*' => $address,
            'cc' => ['nullable', 'array', 'max:100'], 'cc.*' => $address,
            'bcc' => ['nullable', 'array', 'max:100'], 'bcc.*' => $address,
            'subject' => ['nullable', 'string', 'max:998'],
            'html' => ['nullable', 'string', 'max:'.(int) config('mbuniemail.outbox.max_html_bytes')],
            'text' => ['nullable', 'string', 'max:'.(int) config('mbuniemail.outbox.max_html_bytes')],
            'attachment_ids' => ['nullable', 'array', 'max:20'], 'attachment_ids.*' => ['string', 'size:26', 'distinct'],
            'mode' => ['nullable', 'in:new,reply,reply_all,forward'],
            'reply_to_message_id' => ['nullable', 'required_if:mode,reply,reply_all,forward', 'string', 'size:26'],
            'include_original_attachments' => ['nullable', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        [$message, $created] = $this->outbox->queue($user, $key, $data);

        return response()->json(self::present($message), $created ? 202 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(self::present($this->owned($request, $id)));
    }

    public function resend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['confirm_possible_duplicate' => ['nullable', 'boolean']]);

        return response()->json(self::present($this->outbox->resend($this->owned($request, $id), (bool) ($data['confirm_possible_duplicate'] ?? false))), 202);
    }

    public function uploadAttachment(Request $request): JsonResponse
    {
        $maxKb = intdiv((int) config('mbuniemail.outbox.max_attachment_bytes'), 1024);
        $request->validate(['file' => ['required', 'file', 'max:'.$maxKb]]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->outbox->stageAttachment($user, $request->file('file')), 201);
    }

    public function deleteAttachment(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attachment = OutboxAttachment::query()->where('user_id', $user->id)->whereNull('outbox_message_id')->findOrFail($id);

        Storage::disk('local')->delete($attachment->storage_path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    // ---- Drafts (server-side, FR-SEND-007) -------------------------------------------

    public function drafts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => OutboxMessage::query()->where('user_id', $user->id)->where('kind', 'draft')
            ->orderByDesc('updated_at')->limit(100)->get()->map(fn (OutboxMessage $d) => self::presentDraft($d))->values()]);
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'size:26'],
            'to' => ['nullable', 'array', 'max:100'], 'to.*' => ['string', 'max:254'],
            'cc' => ['nullable', 'array', 'max:100'], 'cc.*' => ['string', 'max:254'],
            'bcc' => ['nullable', 'array', 'max:100'], 'bcc.*' => ['string', 'max:254'],
            'subject' => ['nullable', 'string', 'max:998'],
            'html' => ['nullable', 'string', 'max:'.(int) config('mbuniemail.outbox.max_html_bytes')],
            'mode' => ['nullable', 'in:new,reply,reply_all,forward'],
            'reply_to_message_id' => ['nullable', 'string', 'size:26'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $account = $user->usableEmailAccounts()->findOrFail((string) $data['account_id']);

        $draft = OutboxMessage::query()->where('user_id', $user->id)->where('kind', 'draft')->where('idempotency_key', 'draft-'.$id)->first() ?? new OutboxMessage;
        $draft->forceFill([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
            'idempotency_key' => 'draft-'.$id,
            'kind' => 'draft',
            'status' => OutboxMessage::STATUS_DRAFT,
            'payload_enc' => array_intersect_key($data, array_flip(['to', 'cc', 'bcc', 'subject', 'html', 'mode', 'reply_to_message_id'])),
        ])->save();

        return response()->json(self::presentDraft($draft));
    }

    public function deleteDraft(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        OutboxMessage::query()->where('user_id', $user->id)->where('kind', 'draft')->where('idempotency_key', 'draft-'.$id)->firstOrFail()->delete();

        return response()->json(null, 204);
    }

    private function owned(Request $request, string $id): OutboxMessage
    {
        /** @var User $user */
        $user = $request->user();

        return OutboxMessage::query()->where('user_id', $user->id)->where('kind', 'send')->with('emailAccount')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private static function present(OutboxMessage $m): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'message_id' => '<'.$m->message_id_header.'>',
            'recipient_count' => $m->recipient_count,
            'last_error_code' => $m->last_error_code,
            'sent_at' => $m->sent_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function presentDraft(OutboxMessage $d): array
    {
        return ['id' => substr($d->idempotency_key, 6), 'account_id' => $d->email_account_id, ...((array) $d->payload_enc), 'updated_at' => $d->updated_at?->toIso8601String()];
    }
}
