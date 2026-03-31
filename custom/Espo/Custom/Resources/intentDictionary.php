<?php

return [

    'prospect' => [
        'terms' => ['prospect', 'prospects', 'lead', 'leads'],
        'sql' => "engagement_score BETWEEN 10 AND 70"
    ],

    'customer' => [
        'terms' => ['customer', 'customers', 'buyer', 'buyers'],
        'sql' => "engagement_score >= 80"
    ],

    'high_intent' => [
        'terms' => ['high intent', 'ready', 'serious'],
        'sql' => "engagement_score >= 70"
    ],

    'low_intent' => [
        'terms' => ['low intent', 'cold'],
        'sql' => "engagement_score < 30"
    ]

];
