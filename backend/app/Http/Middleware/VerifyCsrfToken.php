<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * CSRF protection for the admin panel, without the JavaScript-readable XSRF-TOKEN
 * cookie (the panel has no JavaScript; forms carry the token). Fewer readable
 * cookies, nothing for an injected script to steal (ZAP 10010).
 */
class VerifyCsrfToken extends PreventRequestForgery
{
    protected $addHttpCookie = false;
}
