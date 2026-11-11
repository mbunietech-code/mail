<?php

namespace Tests\Feature;

use Tests\TestCase;

/** GET /download lists the newest builds; GET /apps/{kind} sends people to the newest file. */
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

    private function build(string $name): void
    {
        $f = public_path("downloads/{$name}");
        file_put_contents($f, 'build');
        $this->made[] = $f;
    }

    private function skipIfRealBuilds(string $glob): void
    {
        foreach (glob(public_path("downloads/{$glob}")) ?: [] as $existing) {
            $this->markTestSkipped("A real build is present: {$existing}");
        }
    }

    public function test_redirects_to_the_highest_version_not_the_latest_name(): void
    {
        $this->skipIfRealBuilds('MbunieMail-*.apk');
        $this->build('MbunieMail-1.0.9.apk');
        $this->build('MbunieMail-1.0.10.apk');
        $this->build('MbunieMail-1.0.2.apk');

        $this->get('/apps/apk')
            ->assertRedirect(asset('downloads/MbunieMail-1.0.10.apk'))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_windows_exe_and_zip_resolve_separately(): void
    {
        $this->skipIfRealBuilds('MbunieMail-*.{exe,zip}');
        $this->build('MbunieMail-1.0.0+9.exe');
        $this->build('MbunieMail-1.0.0+40.exe');
        $this->build('MbunieMail-1.0.0+40-windows.zip');

        $this->get('/apps/windows')->assertRedirect(asset('downloads/'.rawurlencode('MbunieMail-1.0.0+40.exe')));
        $this->get('/apps/windows-zip')->assertRedirect(asset('downloads/'.rawurlencode('MbunieMail-1.0.0+40-windows.zip')));
    }

    public function test_answers_404_when_no_build_is_uploaded(): void
    {
        $this->skipIfRealBuilds('MbunieMail-*.apk');

        $this->get('/apps/apk')->assertNotFound();
    }

    public function test_unknown_kind_is_404(): void
    {
        $this->get('/apps/mac')->assertNotFound();
    }

    public function test_download_page_links_each_available_build(): void
    {
        $this->build('MbunieMail-1.0.0+77.apk');

        $this->get('/download')
            ->assertOk()
            ->assertSee('Download for Android')
            ->assertSee(route('apps.download', 'apk'), false);
    }
}
