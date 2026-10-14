<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    /**
     * Recipient autocomplete (FR-CONT-001): the caller's own correspondents plus active
     * colleagues in the company directory. Never another user's contacts.
     */
    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        /** @var User $user */
        $user = $request->user();
        $like = addcslashes(strtolower($data['q']), '\\%_').'%';

        $own = DB::table('contacts')->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('email', 'like', $like)->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', ['% '.$like]))
            ->orderByDesc('use_count')->orderByDesc('last_used_at')->limit(10)
            ->get(['email', 'name'])->map(fn ($c) => ['email' => $c->email, 'name' => $c->name, 'source' => 'contact']);

        $directory = DB::table('users')->where('organization_id', $user->organization_id)->where('status', User::STATUS_ACTIVE)->where('id', '!=', $user->id)
            ->where(fn ($q) => $q->where('email', 'like', $like)->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', ['% '.$like]))
            ->orderBy('name')->limit(10)
            ->get(['email', 'name'])->map(fn ($u) => ['email' => $u->email, 'name' => $u->name, 'source' => 'directory']);

        return response()->json(['data' => $own->concat($directory)->unique('email')->take(10)->values()]);
    }
}
