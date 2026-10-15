<?php

namespace App\Jobs;

use App\Models\Device;
use App\Models\EmailAccount;
use App\Models\MessageMetadata;
use App\Services\Notifications\FcmSender;
use App\Services\Notifications\NewMailPayload;
use App\Services\UserPreferences;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a "new mail" notification to the account owner's Android devices
 * (FR-NOTIF-001/002, SDD PushNotifyJob). The payload carries ids only; content is
 * reduced to what NewMailPayload allows. Tokens FCM reports as dead are cleared.
 */
class PushNewMailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a late "new mail" alert is worse than none

    public int $timeout = 60;

    /** @param  list<string>  $messageIds  newly synced inbox messages */
    public function __construct(public readonly string $accountId, public readonly array $messageIds)
    {
        $this->onQueue('default');
    }

    public function handle(FcmSender $fcm, UserPreferences $preferences): void
    {
        if (! $fcm->isConfigured()) {
            return;
        }

        $account = EmailAccount::query()->with('user')->find($this->accountId);
        if ($account === null || ! $account->user?->isActive()) {
            return;
        }

        // Skip anything already read elsewhere (e.g. in webmail) before this job ran.
        $unseen = MessageMetadata::query()
            ->whereIn('id', $this->messageIds)
            ->where('email_account_id', $account->id)
            ->where('is_seen', false)
            ->orderByDesc('internal_date')
            ->get();
        if ($unseen->isEmpty()) {
            return;
        }

        $devices = Device::query()
            ->where('user_id', $account->user_id)
            ->where('platform', 'android')
            ->whereNotNull('push_token')
            ->get();
        if ($devices->isEmpty()) {
            return;
        }

        $showSubject = (bool) $preferences->get($account->user)['show_subject_in_notifications'];
        $payload = NewMailPayload::build($unseen->first(), $showSubject, $unseen->count());

        foreach ($devices as $device) {
            if ($fcm->send((string) $device->push_token, $payload) === FcmSender::INVALID_TOKEN) {
                $device->forceFill(['push_token' => null])->save();
            }
        }
    }
}
