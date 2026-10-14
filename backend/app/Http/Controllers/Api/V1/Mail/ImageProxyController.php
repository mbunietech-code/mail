<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Security\ImageProxySigner;
use App\Services\Mail\ImageFetcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class ImageProxyController extends Controller
{
    public function __construct(
        private readonly ImageProxySigner $signer,
        private readonly ImageFetcher $fetcher,
    ) {}

    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'u' => ['required', 'string', 'max:2048'],
            'exp' => ['required', 'integer'],
            'sig' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]+$/'],
        ]);

        // Only URLs MbunieMail signed while rendering a message can be fetched (SEC-T19).
        if (! $this->signer->verify($data['u'], (int) $data['exp'], $data['sig'])) {
            throw ApiException::forbidden('INVALID_SIGNATURE', 'This image link is invalid or has expired.');
        }

        try {
            $image = $this->fetcher->fetch($data['u']);
        } catch (RuntimeException $e) {
            report($e);

            throw new ApiException('IMAGE_UNAVAILABLE', 502, 'The image could not be loaded.');
        }

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline; filename="image"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
