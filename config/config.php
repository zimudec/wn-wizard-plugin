<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global defaults of the wizard plugin
    |--------------------------------------------------------------------------
    |
    | Defaults applied to every wizard instance unless the instance overrides
    | them with its own properties or builder configuration.
    |
    | ttl: server-side sliding inactivity timeout, in minutes (OWASP ASVS
    |      V7.3 recommends an inactivity timeout; 15-30 minutes is a common
    |      range for low-risk applications). Set to null to disable it —
    |      documented as not recommended.
    |
    */

    'ttl' => 30,

];
