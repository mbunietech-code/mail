<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Public app downloads. Release builds are uploaded to public/downloads (not committed):
 *   MbunieMail-<version>.apk           Android
 *   MbunieMail-<version>.exe           Windows, single-file
 *   MbunieMail-<version>-windows.zip   Windows, unpacked folder
 *
 * GET /download lists the newest build of each; GET /apps/{kind} redirects to it so the
 * web server serves the file itself and a large build never streams through PHP.
 */
class AppDownloadController extends Controller
{
    /** kind => [glob, filename regex capturing the version] */
    private const KINDS = [
        'apk' => ['MbunieMail-*.apk', '/^MbunieMail-([0-9][0-9A-Za-z.+-]*)\.apk$/'],
        'windows' => ['MbunieMail-*.exe', '/^MbunieMail-([0-9][0-9A-Za-z.+-]*)\.exe$/'],
        'windows-zip' => ['MbunieMail-*-windows.zip', '/^MbunieMail-([0-9][0-9A-Za-z.+]*)-windows\.zip$/'],
    ];

    public function index(): View
    {
        $builds = [];
        foreach (array_keys(self::KINDS) as $kind) {
            if ($file = self::latest($kind)) {
                $builds[$kind] = [
                    'version' => self::version($kind, $file),
                    'size' => self::size((int) filesize($file)),
                    'url' => route('apps.download', $kind),
                ];
            }
        }

        return view('download', ['builds' => $builds]);
    }

    public function download(string $kind): RedirectResponse
    {
        $file = self::latest($kind) ?? abort(404);

        return redirect()->to(asset('downloads/'.rawurlencode(basename($file))))
            ->header('Cache-Control', 'no-store'); // always resolve to the newest file
    }

    /** Highest version of a kind, by version number rather than by name. */
    private static function latest(string $kind): ?string
    {
        [$glob] = self::KINDS[$kind] ?? abort(404);
        $files = array_values(array_filter(
            glob(public_path('downloads/'.$glob)) ?: [],
            fn (string $f) => self::version($kind, $f) !== null,
        ));
        usort($files, fn (string $a, string $b) => version_compare(self::version($kind, $b), self::version($kind, $a)));

        return $files[0] ?? null;
    }

    private static function version(string $kind, string $path): ?string
    {
        return preg_match(self::KINDS[$kind][1], basename($path), $m) ? $m[1] : null;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
