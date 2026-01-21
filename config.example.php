<?php

return [
    'default' => [
        'driver' => 'mysql',           // ← This determines which normalizer/DSN
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'my_app',
        'username' => 'root',
        'password' => 'secret',
    ],

    'testing' => [
        'driver' => 'sqlite',          // ← Different driver
        'database' => ':memory:',
    ],

    'analytics' => [
        'driver' => 'pgsql',           // ← Postgres
        'host' => 'analytics.example.com',
        'port' => 5432,
        'database' => 'analytics',
        'username' => 'analyst',
        'password' => 'secret',
    ],
];
