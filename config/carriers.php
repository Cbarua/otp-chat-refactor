<?php
// config/carriers.php

/**
 * Configuration for conversion values based on carrier prefixes.
 * Prefixes should be the digits immediately following the country code.
 * (e.g., for 9472..., the prefix is '72')
 */
return [
    'LK' => [ // Sri Lanka
        'country_code' => '94',
        'prefixes' => [
            'hutch' => ['72', '78'],
            'mobitel' => ['70', '71'],
            'dialog' => ['74', '76', '77'],
            'airtel' => ['75'],
        ],
        'values' => [
            'hutch' => 0.015,
            'mobitel' => 0.01,
            'default' => 0.02, // Dialog/Airtel
        ],
        'platform_map' => [
            '70' => 'mspace',
            '71' => 'mspace',
            // All others default to 'ideamart'
        ],
        'platform_default' => 'ideamart'
    ],
    'BD' => [ // Bangladesh
        'country_code' => '880',
        'prefixes' => [
            'robi' => ['18'],
            'airtel' => ['16'],
        ],
        'values' => [
            'robi' => 0.01,
            'airtel' => 0.01,
            'default' => 0.01,
        ],
        'platform_map' => [],
        'platform_default' => 'bdapps'
    ],
];