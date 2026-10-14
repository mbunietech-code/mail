<?php

/*
| Reverse proxies allowed to set X-Forwarded-* headers (risk R-15 / R-21). Read from
| config (not bootstrap env()) so it survives `config:cache`. An empty array — never
| null — means "trust nobody" and prevents Laravel's managed-host wildcard fallback.
| Production behind local Nginx: TRUSTED_PROXIES=127.0.0.1,::1
*/

return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
