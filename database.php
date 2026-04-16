<?php
declare(strict_types=1);
 
function getDB(): PDO {
    $dbUrl = getenv('DATABASE_URL');
 
    if ($dbUrl) {
        $url = parse_url($dbUrl);
        $dsn = "pgsql:host=" . $url['host'] . ";port=" . ($url['port'] ?? 5432) . ";dbname=" . ltrim($url['path'], '/');
        $user = $url['user'];
        $pass = $url['pass'];
    } else {
        $dsn = "pgsql:host=127.0.0.1;port=5432;dbname=postgres";
        $user = "postgres";
        $pass = ""; 
    }
 
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}