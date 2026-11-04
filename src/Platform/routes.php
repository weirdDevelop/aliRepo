<?php
use Eb\Platform\Http;

// [method, path, handler, access]  — access: 'any' | 'admin' | '<page key>' (2.3.0: permission keys)
return [
    ['GET',  '/v2/platform/ping',             [Http::class, 'ping'],     'any'],
    ['GET',  '/v2/platform/health',           [Http::class, 'health'],   'admin'],
    ['GET',  '/v2/platform/jobs',             [Http::class, 'jobs'],     'admin'],
    ['POST', '/v2/platform/jobs/{id}/retry',  [Http::class, 'retryJob'], 'admin'],
];
