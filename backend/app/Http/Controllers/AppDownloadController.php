<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

/**
 * App downloads in public/downloads (not committed). The web server serves the files
 * themselves, so a large build never streams through PHP. Releases are uploaded as
 *   MbunieMail-<version>+<build>.apk          (Android)
 *   MbunieMail-<version>+<build>.exe          (Windows installer, for new PCs)
 *   MbunieMail-<version>+<build>-windows.zip  (Windows update: the contents of the Release folder)
 * where <build> is the --build-number the app was built with; app/windows/installer/
 * build-installer.ps1 makes both Windows files. The apps update themselves from
 * GET /apps/latest.json.
 */
class AppDownloadController extends Controller
{
    private const PLATFORMS = [
        'android' => '/^MbunieMail-([0-9][0-9A-Za-z.+]*)\.apk$/',
        'windows' => '/^MbunieMail-([0-9][0-9A-Za-z.+]*)-windows\.zip$/',
        'windows-installer' => '/^MbunieMail-([0-9][0-9A-Za-z.+]*)\.exe$/',
    ];

    /** Platforms whose apps update themselves (the installer is only for new PCs). */
    private const UPDATING = ['android', 'windows'];

    /** GET /apps/apk: the newest Android build, for people installing by hand. */
    public function latestApk(): RedirectResponse
    {
        return self::redirectToLatest('android');
    }

    /** GET /apps/windows: the newest Windows installer. */
    public function latestWindowsInstaller(): RedirectResponse
    {
        return self::redirectToLatest('windows-installer');
    }

    private static function redirectToLatest(string $platform): RedirectResponse
    {
        $latest = self::latest($platform) ?? abort(404);

        return redirect()->to(self::url($latest))
            ->header('Cache-Control', 'no-store'); // always resolve to the newest file
    }

    /**
     * GET /apps/latest.json: the newest build per platform. The app installs a build
     * whose build number is above its own, after checking the SHA-256.
     */
    public function manifest(): JsonResponse
    {
        $out = [];
        foreach (self::UPDATING as $platform) {
            $file = self::latest($platform);
            $version = $file === null ? null : self::version($platform, $file);
            $out[$platform] = $file === null ? null : [
                'version' => explode('+', $version)[0],
                'build' => str_contains($version, '+') ? (int) explode('+', $version)[1] : null,
                'url' => self::url($file),
                'size' => filesize($file),
                'sha256' => self::sha256($file),
            ];
        }

        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    private static function latest(string $platform): ?string
    {
        $files = array_values(array_filter(
            glob(public_path('downloads/MbunieMail-*')) ?: [],
            fn (string $f) => self::version($platform, $f) !== null,
        ));
        // version_compare reads "1.0.0+57" as 1.0.0.57, so the build number counts too.
        usort($files, fn (string $a, string $b) => version_compare((string) self::version($platform, $b), (string) self::version($platform, $a)));

        return $files[0] ?? null;
    }

    private static function version(string $platform, string $path): ?string
    {
        return preg_match(self::PLATFORMS[$platform], basename($path), $m) ? $m[1] : null;
    }

    private static function url(string $path): string
    {
        return asset('downloads/'.rawurlencode(basename($path)));
    }

    /** Hashing a 60 MB build takes a moment, so it is done once per uploaded file. */
    private static function sha256(string $path): string
    {
        $key = 'app-download-sha256:'.basename($path).':'.filemtime($path).':'.filesize($path);

        return Cache::rememberForever($key, fn () => (string) hash_file('sha256', $path));
    }
}
