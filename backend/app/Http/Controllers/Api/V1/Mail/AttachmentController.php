<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Http\Controllers\Controller;
use App\Models\AttachmentMetadata;
use App\Models\MessageMetadata;
use App\Models\User;
use App\Services\Mail\MessageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Attachment download (FR-SEND-003 / risk R-10). Bytes are streamed from the provider
 * and never stored. Responses are always opaque downloads so a browser can never
 * render attacker-controlled HTML/SVG in MbunieMail's origin; only verified raster
 * images may be shown inline.
 */
class AttachmentController extends Controller
{
    private const INLINE_SAFE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(private readonly MessageService $messages) {}

    public function show(Request $request, string $id): Response
    {
        /** @var User $user */
        $user = $request->user();
        $attachment = AttachmentMetadata::query()
            ->whereIn('message_metadata_id', MessageMetadata::query()->ownedBy($user)->select('id'))
            ->with('message.folder', 'message.emailAccount.providerProfile')
            ->findOrFail($id);

        $file = $this->messages->attachment($attachment);

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($file['bytes']) ?: 'application/octet-stream';
        $inline = $request->boolean('inline') && in_array($detected, self::INLINE_SAFE, true);

        return response($file['bytes'], 200, [
            'Content-Type' => $inline ? $detected : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes(preg_replace('/[^\x20-\x7E]/', '_', $file['filename']) ?? 'attachment', '"\\').'"; filename*=UTF-8\'\''.rawurlencode($file['filename']),
            'Content-Length' => (string) strlen($file['bytes']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'X-Detected-Content-Type' => $detected,
        ]);
    }
}
