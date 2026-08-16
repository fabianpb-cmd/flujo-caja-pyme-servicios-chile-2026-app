<?php

return [
    'hsts_enabled' => env('SECURITY_HSTS_ENABLED', in_array(env('APP_ENV', 'local'), ['staging', 'production'], true)),
];
