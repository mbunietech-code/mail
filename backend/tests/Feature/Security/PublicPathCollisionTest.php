<?php

namespace Tests\Feature\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * A directory in public/ named like a route prefix (e.g. public/admin) makes the web
 * server serve the directory instead of the application, which tests cannot see.
 * Found during a browser check in Phase 7.
 */
class PublicPathCollisionTest extends TestCase
{
    protected bool $seed = false;

    public function test_no_public_directory_shadows_a_route_prefix(): void
    {
        $prefixes = collect(RouteFacade::getRoutes()->getRoutes())
            ->map(fn (Route $r) => explode('/', trim($r->uri(), '/'))[0])
            ->filter(fn (string $segment) => $segment !== '' && ! str_starts_with($segment, '{'))
            ->unique();

        foreach ($prefixes as $prefix) {
            $this->assertDirectoryDoesNotExist(public_path($prefix), "public/{$prefix} would shadow the /{$prefix} routes");
        }
    }
}
