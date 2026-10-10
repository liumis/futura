<?php

declare(strict_types=1);

// Force sqlite for the suite even when the process/job inherits MySQL DB_* (CI migrate step).
putenv('DB_CONNECTION=sqlite');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_CONNECTION'] = 'sqlite';

putenv('DB_DATABASE=:memory:');
$_ENV['DB_DATABASE'] = ':memory:';
$_SERVER['DB_DATABASE'] = ':memory:';

putenv('DB_URL');
unset($_ENV['DB_URL'], $_SERVER['DB_URL']);

foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    putenv($key.'=');
    $_ENV[$key] = '';
    $_SERVER[$key] = '';
}

require dirname(__DIR__).'/vendor/autoload.php';
