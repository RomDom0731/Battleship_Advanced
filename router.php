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

// --- Production Endpoints ---

// POST /api/reset
if ($method === 'POST' && $segments[0] === 'reset' && count($segments) === 1) {
    resetSystem(); exit;
}

// POST /api/players
if ($method === 'POST' && $segments[0] === 'players' && count($segments) === 1) {
    createPlayer(); exit;
}

// GET /api/players/{id}/stats
if ($method === 'GET' && $segments[0] === 'players' && isset($segments[2]) && $segments[2] === 'stats') {
    getPlayer((int)$segments[1]); exit; // Note: Ensure getPlayer() handles the stats response format
}

// POST /api/games
if ($method === 'POST' && $segments[0] === 'games' && count($segments) === 1) {
    createGame(); exit;
}

// POST /api/games/{id}/join
if ($method === 'POST' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'join') {
    joinGame((int)$segments[1]); exit;
}

// GET /api/games/{id}
if ($method === 'GET' && $segments[0] === 'games' && count($segments) === 2) {
    getGame((int)$segments[1]); exit;
}

// POST /api/games/{id}/place
if ($method === 'POST' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'place') {
    placeShips((int)$segments[1]); exit;
}

// POST /api/games/{id}/fire
if ($method === 'POST' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'fire') {
    fireShot((int)$segments[1]); exit;
}

// GET /api/games/{id}/moves
if ($method === 'GET' && $segments[0] === 'games' && isset($segments[2]) && $segments[2] === 'moves') {
    getGameMoves((int)$segments[1]); exit;
}

// --- Test Mode Endpoints ---

// POST /api/test/games/{id}/restart
if ($method === 'POST' && $segments[0] === 'test' && $segments[1] === 'games' && isset($segments[3]) && $segments[3] === 'restart') {
    testResetGame((int)$segments[2]); exit;
}

// POST /api/test/games/{id}/ships
if ($method === 'POST' && $segments[0] === 'test' && $segments[1] === 'games' && isset($segments[3]) && $segments[3] === 'ships') {
    testPlaceShips((int)$segments[2]); exit;
}

// GET /api/test/games/{id}/board/{player_id}
if ($method === 'GET' && $segments[0] === 'test' && $segments[1] === 'games' && isset($segments[3]) && $segments[3] === 'board') {
    $playerId = isset($_GET['playerId']) ? (int)$_GET['playerId'] : 0;
    testGetBoard((int)$segments[2], $playerId); 
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);