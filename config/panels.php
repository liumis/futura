<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filament panel hostnames
    |--------------------------------------------------------------------------
    |
    | Production (Laravel Cloud): set both domains so each panel owns its host
    | at path "/". Leave empty for local single-host mode:
    |   - Admin:    http://127.0.0.1:8001/
    |   - Customer: http://127.0.0.1:8001/customer
    |
    */

    'admin_domain' => env('INTERNAL_PANEL_DOMAIN'),

    'customer_domain' => env('CUSTOMER_PANEL_DOMAIN'),

];
