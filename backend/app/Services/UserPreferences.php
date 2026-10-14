<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Per-user preferences (FR-SET-001) with safe defaults: remote images ask, subjects
 * hidden in notifications.
 */
class UserPreferences
{
    public const DEFAULTS = [
        'theme' => 'system',
        'density' => 'comfortable',
        'default_account_id' => null,
        'remote_images' => 'ask',
        'show_subject_in_notifications' => false,
        'trusted_image_senders' => [],
    ];

    /** @return array<string, mixed> */
    public function get(User $user): array
    {
        $row = DB::table('user_preferences')->where('user_id', $user->id)->first();
        if ($row === null) {
            return self::DEFAULTS;
        }

        return [
            'theme' => $row->theme,
            'density' => $row->density,
            'default_account_id' => $row->default_account_id,
            'remote_images' => $row->remote_images,
            'show_subject_in_notifications' => (bool) $row->show_subject_in_notifications,
            'trusted_image_senders' => json_decode((string) ($row->trusted_image_senders ?? '[]'), true) ?: [],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  already validated
     * @return array<string, mixed>
     */
    public function update(User $user, array $changes): array
    {
        $merged = array_merge($this->get($user), $changes);
        DB::table('user_preferences')->updateOrInsert(
            ['user_id' => $user->id],
            [...$merged, 'trusted_image_senders' => json_encode(array_values($merged['trusted_image_senders'])), 'updated_at' => now(), 'created_at' => now()],
        );

        return $this->get($user);
    }

    public function trustsImagesFrom(User $user, ?string $email): bool
    {
        if ($email === null) {
            return false;
        }
        $prefs = $this->get($user);

        return $prefs['remote_images'] === 'trusted_senders'
            && in_array(strtolower($email), array_map('strtolower', $prefs['trusted_image_senders']), true);
    }
}
