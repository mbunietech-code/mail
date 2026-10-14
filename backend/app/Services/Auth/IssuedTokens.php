<?php

namespace App\Services\Auth;

use App\Models\RefreshSession;
use App\Models\User;

final readonly class IssuedTokens
{
    public function __construct(
        public User $user,
        public RefreshSession $session,
        #[\SensitiveParameter] public string $accessToken,
        public int $accessExpiresIn,
        #[\SensitiveParameter] public string $refreshToken,
        public int $refreshExpiresIn,
    ) {}

    /** @return array<string, mixed> */
    public function toResponse(): array
    {
        return [
            'status' => 'authenticated',
            'access_token' => $this->accessToken,
            'access_expires_in' => $this->accessExpiresIn,
            'refresh_token' => $this->refreshToken,
            'refresh_expires_in' => $this->refreshExpiresIn,
            'session_id' => $this->session->family_id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'mfa_enabled' => $this->user->hasMfaEnabled(),
            ],
        ];
    }
}
