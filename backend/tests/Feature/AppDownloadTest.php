<?php

namespace Tests\Feature;

use Tests\TestCase;

/** GET /apps/apk sends people to the newest uploaded Android build. */
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
        $f = public_path("downloads/MbunieMail-{$version}.apk");
        file_put_contents($f, 'apk');
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
}
