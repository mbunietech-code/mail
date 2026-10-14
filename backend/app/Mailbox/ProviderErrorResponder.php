<?php

namespace App\Mailbox;

use App\Exceptions\ApiException;

/** Converts provider failures into client-safe API errors (code + remedy only). */
final class ProviderErrorResponder
{
    public static function toApi(ProviderError $e): ApiException
    {
        [$status, $code] = match ($e->errorCode) {
            ProviderErrorCode::AuthFailed => [422, 'PROVIDER_AUTH_FAILED'],
            ProviderErrorCode::NotSupported => [422, 'PROVIDER_NOT_SUPPORTED'],
            ProviderErrorCode::Rejected => [422, 'PROVIDER_REJECTED'],
            ProviderErrorCode::QuotaExceeded => [422, 'PROVIDER_QUOTA_EXCEEDED'],
            ProviderErrorCode::MailboxGone, ProviderErrorCode::MessageGone => [404, 'NOT_FOUND'],
            ProviderErrorCode::Timeout => [504, 'PROVIDER_TIMEOUT'],
            ProviderErrorCode::Throttled => [503, 'PROVIDER_THROTTLED'],
            ProviderErrorCode::TlsError => [502, 'PROVIDER_TLS_ERROR'],
            ProviderErrorCode::HostNotAllowed => [502, 'PROVIDER_HOST_NOT_ALLOWED'],
            default => [502, 'PROVIDER_UNREACHABLE'],
        };

        $headers = $e->errorCode === ProviderErrorCode::Throttled ? ['Retry-After' => (string) ($e->retryAfterSeconds ?? 60)] : [];

        return new ApiException($code, $status, 'The mail provider request failed.', $e->errorCode->remedy(), $headers);
    }
}
