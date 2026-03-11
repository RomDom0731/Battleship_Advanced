<?php
declare(strict_types=1);

require_once __DIR__ . '/controllers.php';

$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', ltrim(rtrim($uri, '/'), '/'));

// Find where 'api' is in the segments to account for subfolders like /Battleship_Advanced/
$apiIndex = array_search('api', $segments);

if ($apiIndex === false) {
    http_response_code(404);
    echo json_encode(['error' => 'All endpoints must start with /api']);
    exit;
}

// Slice the array so that $segments[0] is the part after /api/ (e.g., 'players')
$segments = array_slice($segments, $apiIndex + 1);

if ($method === 'POST' && $segments[0] === 'players' && count($segments) === 1) {
    createPlayer(); exit;
}
if ($method === 'GET' && $segments[0] === 'players' && count($segments) === 2) {
    getPlayer((int)$segments[1]); exit;
}
if ($method === 'POST' && $segments[0] === 'games' && count($segments) === 1) {
    createGame(); exit;
}
if ($method === 'POST' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'join') {
    joinGame((int)$segments[1]); exit;
}
if ($method === 'GET' && $segments[0] === 'games' && count($segments) === 2) {
    getGame((int)$segments[1]); exit;
}
if ($method === 'POST' && $segments[0] === 'test' && isset($segments[3]) && $segments[3] === 'ships') {
    testPlaceShips((int)$segments[2]); exit;
}
if ($method === 'GET' && $segments[0] === 'test' && isset($segments[3]) && $segments[3] === 'board') {
    testGetBoard((int)$segments[2]); exit;
}
if ($method === 'POST' && $segments[0] === 'test' && isset($segments[3]) && $segments[3] === 'reset') {
    testResetGame((int)$segments[2]); exit;
}
if ($method === 'POST' && $segments[0] === 'test' && isset($segments[3]) && $segments[3] === 'set-turn') {
    testSetTurn((int)$segments[2]); exit;
}

if ($method === 'POST' && $segments[0] === 'reset' && count($segments) === 1) {
    resetSystem(); exit;
}

if ($method === 'GET' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'moves') {
    getGameMoves((int)$segments[1]); exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);