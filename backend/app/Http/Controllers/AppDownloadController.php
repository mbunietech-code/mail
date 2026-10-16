<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * GET /apps/apk: the newest Android build in public/downloads. The web server then serves
 * the file itself, so a large APK never streams through PHP. Releases are uploaded
 * as public/downloads/MbunieMail-<version>.apk (not committed).
 */
class AppDownloadController extends Controller
{
    public function latestApk(): RedirectResponse
    {
        $files = glob(public_path('downloads/MbunieMail-*.apk')) ?: [];
        usort($files, fn (string $a, string $b) => version_compare(self::version($b), self::version($a)));
        $latest = $files[0] ?? abort(404);

        return redirect()->to(asset('downloads/'.rawurlencode(basename($latest))))
            ->header('Cache-Control', 'no-store'); // always resolve to the newest file
    }

    private static function version(string $path): string
    {
        return preg_match('/MbunieMail-([0-9][0-9A-Za-z.+-]*)\.apk$/', $path, $m) ? $m[1] : '0';
    }
}
