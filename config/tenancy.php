<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | The root domain PeopleOS is served from. A request's subdomain is
    | resolved by stripping this value from the Host header. Locally this
    | is peopleos.test; production will override via APP_DOMAIN.
    |
    */

    'base_domain' => env('APP_DOMAIN', 'peopleos.test'),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | Subdomains that must never resolve to a tenant, reserved for
    | platform-level routes (e.g. super admin console, marketing site).
    |
    */

    'reserved_subdomains' => [
        'www',
        'api',
        'admin',
    ],

];
