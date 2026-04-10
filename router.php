<?php
declare(strict_types=1);

require_once __DIR__ . '/controllers.php';

$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', ltrim(rtrim($uri, '/'), '/'));

// Metadata, Version, and Health can exist at the root or /api
if ($method === 'GET' && (empty($segments[0]) || $segments[0] === 'api' && count($segments) === 1)) {
    getMetadata(); exit;
}

// Ensure all subsequent endpoints start with /api
$apiIndex = array_search('api', $segments);
if ($apiIndex === false) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => 'All endpoints must start with /api']);
    exit;
}

// Re-index segments relative to /api/
$segments = array_slice($segments, $apiIndex + 1);

// --- Player Endpoints ---
if ($segments[0] === 'players') {
    if ($method === 'POST' && count($segments) === 1) {
        createPlayer(); exit;
    }
    if ($method === 'GET' && isset($segments[2]) && $segments[2] === 'stats') {
        getPlayer((int)$segments[1]); exit; // Matches /api/players/{id}/stats
    }
}

// --- Game Endpoints ---
if ($segments[0] === 'games') {
    if ($method === 'POST' && count($segments) === 1) {
        createGame(); exit;
    }
    if (isset($segments[1]) && is_numeric($segments[1])) {
        $gameId = (int)$segments[1];
        
        if ($method === 'GET' && count($segments) === 2) {
            getGame($gameId); exit;
        }
        if ($method === 'POST' && isset($segments[2])) {
            if ($segments[2] === 'join') { joinGame($gameId); exit; }
            if ($segments[2] === 'place') { placeShips($gameId); exit; }
            if ($segments[2] === 'fire') { fireShot($gameId); exit; }
        }
        if ($method === 'GET' && isset($segments[2]) && $segments[2] === 'moves') {
            getGameMoves($gameId); exit;
        }
    }
}

// --- Test Endpoints ---
if ($segments[0] === 'test' && $segments[1] === 'games' && isset($segments[2])) {
    $gameId = (int)$segments[2];
    if ($method === 'POST' && isset($segments[3])) {
        if ($segments[3] === 'restart') { testResetGame($gameId); exit; }
        if ($segments[3] === 'ships') { testPlaceShips($gameId); exit; }
    }
    if ($method === 'GET' && isset($segments[3]) && $segments[3] === 'board') {
        $playerId = $segments[4] ?? $_GET['player_id'] ?? null;
        if (!$playerId) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'player_id required']);
            exit;
        }
        testGetBoard($gameId, (int)$playerId); exit;
    }
}

// Fallback for unknown routes
http_response_code(404);
echo json_encode(['error' => 'not_found', 'message' => 'Endpoint not found']);