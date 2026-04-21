<?php
declare(strict_types=1);

require_once __DIR__ . '/controllers.php';

$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = explode('/', ltrim(rtrim($uri, '/'), '/'));

// Root or /api → metadata
if ($method === 'GET' && (empty($segments[0]) || ($segments[0] === 'api' && count($segments) === 1))) {
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

// --- System Endpoints ---
if ($method === 'GET' && isset($segments[0]) && $segments[0] === 'health') {
    getHealth(); exit;
}
if ($method === 'POST' && isset($segments[0]) && $segments[0] === 'reset') {
    resetSystem(); exit;
}

// --- Player Endpoints ---
if (isset($segments[0]) && $segments[0] === 'players') {
    if ($method === 'GET' && count($segments) === 1) {
        getPlayers(); exit;
    }
    if ($method === 'POST' && count($segments) === 1) {
        createPlayer(); exit;
    }
    if ($method === 'GET' && isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'stats') {
        getPlayer((int)$segments[1]); exit;
    }
}

// --- Test / Autograder Endpoints ---
// Must be checked BEFORE game endpoints so /api/test/games/... isn't caught by the games block.
if (isset($segments[0]) && $segments[0] === 'test') {
    // Apache converts X-Test-Password header to HTTP_X_TEST_PASSWORD in $_SERVER
    // This is more reliable than getallheaders() on Render/Apache/CGI setups
    $password = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';

    // Also check apache_request_headers() as a fallback
    if ($password === '' && function_exists('apache_request_headers')) {
        $apacheHeaders = apache_request_headers();
        foreach ($apacheHeaders as $k => $v) {
            if (strtolower($k) === 'x-test-password') {
                $password = $v;
                break;
            }
        }
    }

    // Also try getallheaders() as another fallback
    if ($password === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'x-test-password') {
                $password = $v;
                break;
            }
        }
    }

    if ($password !== 'clemson-test-2026') {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'message' => 'Access denied']);
        exit;
    }

    if (isset($segments[1]) && $segments[1] === 'games' && isset($segments[2])) {
        $gameId = (int)$segments[2];

        if ($method === 'POST' && isset($segments[3])) {
            if ($segments[3] === 'restart') { testResetGame($gameId);  exit; }
            if ($segments[3] === 'ships')   { testPlaceShips($gameId); exit; }
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

    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'message' => 'Test endpoint not found']);
    exit;
}

// --- Game Endpoints ---
if (isset($segments[0]) && $segments[0] === 'games') {
    if ($method === 'GET' && count($segments) === 1) {
        getGames(); exit;
    }
    if ($method === 'POST' && count($segments) === 1) {
        createGame(); exit;
    }
    if (isset($segments[1]) && is_numeric($segments[1])) {
        $gameId = (int)$segments[1];

        if ($method === 'GET' && count($segments) === 2) {
            getGame($gameId); exit;
        }
        if ($method === 'GET' && isset($segments[2]) && $segments[2] === 'moves') {
            getGameMoves($gameId); exit;
        }
        if ($method === 'POST' && isset($segments[2])) {
            if ($segments[2] === 'join')  { joinGame($gameId);   exit; }
            if ($segments[2] === 'place') { placeShips($gameId); exit; }
            if ($segments[2] === 'fire')  { fireShot($gameId);   exit; }
        }
    }
}

// Fallback
http_response_code(404);
echo json_encode(['error' => 'not_found', 'message' => 'Endpoint not found']);