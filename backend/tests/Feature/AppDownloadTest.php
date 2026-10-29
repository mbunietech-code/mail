<?php

namespace Tests\Feature;

use Tests\TestCase;

/** GET /apps/apk and /apps/latest.json point at the newest uploaded builds. */
class AppDownloadTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function apk(string $version): void
    {
        $this->upload("MbunieMail-{$version}.apk");
    }

    private function upload(string $name, string $content = 'apk'): void
    {
        $f = public_path("downloads/{$name}");
        file_put_contents($f, $content);
        $this->made[] = $f;
    }

    public function test_redirects_to_the_highest_version_not_the_latest_name(): void
    {
        $this->apk('1.0.9');
        $this->apk('1.0.10');
        $this->apk('1.0.2');

        $this->get('/apps/apk')
            ->assertRedirect(asset('downloads/MbunieMail-1.0.10.apk'))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_answers_404_when_no_build_is_uploaded(): void
    {
        foreach (glob(public_path('downloads/MbunieMail-*.apk')) ?: [] as $existing) {
            $this->markTestSkipped("A real build is present: {$existing}");
        }

        $this->get('/apps/apk')->assertNotFound();
    }

    public function test_manifest_lists_the_newest_build_per_platform_with_its_hash(): void
    {
        foreach (glob(public_path('downloads/MbunieMail-*')) ?: [] as $existing) {
            $this->markTestSkipped("A real build is present: {$existing}");
        }
        $this->apk('1.0.0+9');
        $this->apk('1.0.0+10');
        $this->upload('MbunieMail-1.0.0+12-windows.zip', 'zip');

        $this->get('/apps/latest.json')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'android' => [
                    'version' => '1.0.0',
                    'build' => 10,
                    'url' => asset('downloads/'.rawurlencode('MbunieMail-1.0.0+10.apk')),
                    'size' => 3,
                    'sha256' => hash('sha256', 'apk'),
                ],
                'windows' => [
                    'version' => '1.0.0',
                    'build' => 12,
                    'url' => asset('downloads/'.rawurlencode('MbunieMail-1.0.0+12-windows.zip')),
                    'size' => 3,
                    'sha256' => hash('sha256', 'zip'),
                ],
            ]);
    }

    public function test_windows_builds_are_never_offered_as_the_apk(): void
    {
        $this->apk('1.0.0+1');
        $this->upload('MbunieMail-2.0.0+5-windows.zip', 'zip');

        $this->get('/apps/apk')->assertRedirect(asset('downloads/'.rawurlencode('MbunieMail-1.0.0+1.apk')));
    }

    public function test_windows_installer_redirects_to_the_newest_exe(): void
    {
        $this->upload('MbunieMail-1.0.0+3.exe', 'exe');
        $this->upload('MbunieMail-1.0.0+12.exe', 'exe');
        $this->upload('MbunieMail-1.0.0+20-windows.zip', 'zip');

        $this->get('/apps/windows')
            ->assertRedirect(asset('downloads/'.rawurlencode('MbunieMail-1.0.0+12.exe')))
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
