<?php

return [

    // BEHAVIOR
    'explore' => [
        'variants' => ['explored', 'exploring'],
        'type' => 'behavior'
    ],

    'read' => [
        'variants' => ['read'],
        'type' => 'behavior'
    ],

    // CONTENT
    'article' => [
        'variants' => ['article'],
        'type' => 'content'
    ],

    // DESTINATION
    'kyoto' => [
        'variants' => ['kyoto'],
        'type' => 'destination',
        'parent' => 'japan' 
    ],

    'japan' => [
        'variants' => ['kyoto', 'jp', 'nihon'],
        'type' => 'destination'
    ],

    // THEMES
    'blue' => [
        'variants' => ['blue'],
        'type' => 'theme'
    ],

    'textile' => [
        'variants' => ['textile', 'silk'],
        'type' => 'theme'
    ],

    // PRODUCTS
    'tour' => [
        'variants' => ['tour'],
        'type' => 'product'
    ],

    'retreat' => [
        'variants' => ['retreat'],
        'type' => 'product'
    ],

    // CRAFT
    'quilt' => [
        'variants' => ['quilt', 'quilted'],
        'type' => 'craft'
    ],

    'curves' => [
        'variants' => ['curves', 'curved'],
        'type' => 'craft'
    ],

];
