<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\RefreshSession;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers the FCM token of the device behind the current session (FR-NOTIF-001).
 * The device is derived from the session, never from client input, so a user can only
 * route their own notifications to a device they signed in on.
 */
class PushTokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $device = $this->currentDevice($request);
        if ($device === null) {
            throw new ApiException('NO_DEVICE', 409, 'No device is linked to this session.');
        }

        // A token belongs to one install; drop it from any other row (e.g. after re-login).
        Device::query()->whereKeyNot($device->id)->where('user_id', $device->user_id)->get()
            ->filter(fn (Device $d) => $d->push_token === $data['token'])
            ->each(fn (Device $d) => $d->forceFill(['push_token' => null])->save());

        $device->forceFill(['push_token' => $data['token'], 'last_seen_at' => now()])->save();

        return response()->json(null, 204);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->currentDevice($request)?->forceFill(['push_token' => null])->save();

        return response()->json(null, 204);
    }

    private function currentDevice(Request $request): ?Device
    {
        /** @var User $user */
        $user = $request->user();
        $family = $this->tokens->currentFamilyId($user);
        $deviceId = $family === null ? null : RefreshSession::query()->where('family_id', $family)->whereNotNull('device_id')->value('device_id');

        return $deviceId === null ? null : Device::query()->where('user_id', $user->id)->find($deviceId);
    }
}
