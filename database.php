<?php
declare(strict_types=1);

function getDB(): PDO {
    // If DATABASE_URL is set (on Render), use it. Otherwise, use local defaults.
    $dbUrl = getenv('DATABASE_URL'); // Render provides this

    if ($dbUrl) {
        // Parse the Render connection string
        $url = parse_url($dbUrl);
        $dsn = "pgsql:host=" . $url['host'] . ";port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/');
        $user = $url['user'];
        $pass = $url['pass'];
    } else {
        // Your existing local XAMPP settings
        $dsn = "pgsql:host=127.0.0.1;port=5432;dbname=postgres";
        $user = "postgres";
        $pass = "";
    }

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}