<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function __construct(private readonly UserPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->preferences->get($user));
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'theme' => ['sometimes', 'in:system,light,dark'],
            'density' => ['sometimes', 'in:comfortable,compact'],
            'default_account_id' => ['sometimes', 'nullable', 'string', Rule::exists('email_accounts', 'id')->where('user_id', $user->id)],
            'remote_images' => ['sometimes', 'in:never,ask,trusted_senders'],
            'show_subject_in_notifications' => ['sometimes', 'boolean'],
            'trusted_image_senders' => ['sometimes', 'array', 'max:200'],
            'trusted_image_senders.*' => ['email', 'max:254'],
        ]);

        return response()->json($this->preferences->update($user, $data));
    }
}
