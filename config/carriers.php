<?php
// config/carriers.php

/**
 * Configuration for conversion values based on carrier prefixes.
 * Prefixes should be the digits immediately following the country code.
 * (e.g., for 9472..., the prefix is '72')
 */
$randomizer = new \Random\Randomizer();

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
            'hutch' => $randomizer->getFloat(1.0, 1.5) / 100, // Random value between 0.01 and 0.015
            'mobitel' => $randomizer->getFloat(0, 1) / 100,  // Random value between 0 and 0.01
            'default' => $randomizer->getFloat(1.5, 2) / 100, // Dialog/Airtel Random value between 0.015 and 0.02
        ],
        'platform_map' => [
            '70' => 'mspace',
            '71' => 'mspace',
            '72' => 'ideamart',
            '74' => 'ideamart',
            '75' => 'ideamart',
            '76' => 'ideamart',
            '77' => 'ideamart',
            '78' => 'ideamart',
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
        'platform_map' => [
            '16' => 'bdapps',
            '18' => 'bdapps',
        ],
        'platform_default' => 'bdapps'
    ],
];