<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token
    |--------------------------------------------------------------------------
    |
    | Optional override. Prefer config/services.php so the token is stored when
    | configuration is cached. A "token" on the mailgazelle mailer wins over
    | this value and over services.mailgazelle.token.
    |
    */

    'token' => env('MAILGAZELLE_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | API root
    |--------------------------------------------------------------------------
    |
    | Null uses https://mailgazelle.com/api/v1. After config:cache, set this in
    | the published file or on the mailgazelle mailer. env() in this file is
    | not read from .env once configuration is cached unless the file is
    | published.
    |
    */

    'base_url' => env('MAILGAZELLE_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Total request timeout and connection timeout, in seconds.
    |
    */

    'timeout' => env('MAILGAZELLE_TIMEOUT', 30),

    'connect_timeout' => env('MAILGAZELLE_CONNECT_TIMEOUT', 10),

];
