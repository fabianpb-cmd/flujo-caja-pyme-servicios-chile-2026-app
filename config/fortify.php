<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/dashboard',
    'limiters' => [
        'two-factor' => 'two-factor',
    ],
    'redirects' => [
        'login' => '/dashboard',
        'logout' => '/login',
        'password-confirmation' => '/mi-cuenta/seguridad',
    ],
    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
        ]),
    ],
];
