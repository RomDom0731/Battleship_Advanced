<?php
declare(strict_types=1);

// POST /players
function createPlayer(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $playerName = $body['playerName'] ?? $body['username'] ?? null;

    if (!$playerName || trim((string)$playerName) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'playerName is required']);
        return;
    }
    
    $displayName = trim((string)$playerName);

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT player_id, display_name FROM players WHERE display_name = :name');
        $stmt->execute([':name' => $displayName]);
        $existing = $stmt->fetch();

        if ($existing) {
            http_response_code(200);
            echo json_encode([
                'player_id'   => (int)$existing['player_id'],
                'displayName' => $existing['display_name']
            ]);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO players (display_name) VALUES (:name) RETURNING player_id, display_name, created_at'
        );
        $stmt->execute([':name' => $displayName]);
        $player = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'player_id'   => (int)$player['player_id'],
            'displayName' => $player['display_name'],
            'createdAt'   => $player['created_at']
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// GET /players/{playerId}
function getPlayer(int $player_id): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        $player = $stmt->fetch();

        if (!$player) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
            return;
        }

        $totalShots = (int)$player['total_moves'];
        $totalHits = (int)$player['total_hits'];
        $accuracy = $totalShots > 0 ? round($totalHits / $totalShots, 3) : 0;

        http_response_code(200);
        echo json_encode([
            'games_played' => (int)$player['total_games'],
            'wins'         => (int)$player['total_wins'],
            'losses'       => (int)$player['total_losses'],
            'total_shots'  => $totalShots,
            'total_hits'   => $totalHits,
            'accuracy'     => $accuracy
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /games
function createGame(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // FIX: Use null coalescing and ensure the values are checked even if they are 0 or empty
    $gridSize   = $body['gridSize'] ?? null;
    $maxPlayers = $body['maxPlayers'] ?? null;

    // Default values if not provided
    if ($gridSize === null) $gridSize = 10;
    if ($maxPlayers === null) $maxPlayers = 2;

    // FIX: Ensure the validation triggers correctly for out-of-bounds values
    if ((int)$gridSize < 5 || (int)$gridSize > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'gridSize must be between 5 and 15']);
        return;
    }

    if ($maxPlayers < 1 || $maxPlayers > 4) {
        http_response_code(400);
        echo json_encode(['error' => 'maxPlayers must be between 1 and 4']);
        return;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare(
            "INSERT INTO games (grid_size, max_players, status)
             VALUES (:gridSize, :maxPlayers, 'waiting')
             RETURNING game_id, grid_size, max_players, status, created_at"
        );
        $stmt->execute([':gridSize' => $gridSize, ':maxPlayers' => $maxPlayers]);
        $game = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'game_id'    => $game['game_id'],
            'gridSize'   => (int)$game['grid_size'],
            'maxPlayers' => (int)$game['max_players'],
            'status'     => $game['status'],
            'createdAt'  => $game['created_at']
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /games/{gameId}/join
function joinGame(int $game_id): void {
    try {
        $db = getDB();
        
        // 1. Check if game exists FIRST to return 404 for bogus game_id
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        // 2. Validate request body
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['player_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'player_id is required']);
            return;
        }

        $player_id = $body['player_id'];

        // 3. Check if game is still accepting players
        if ($game['status'] !== 'waiting') {
            http_response_code(400);
            echo json_encode(['error' => 'Game is no longer accepting players']);
            return;
        }

        // 4. Verify player exists in the players table
        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
            return;
        }

        // 5. Check if player is already in this game
        $stmt = $db->prepare('SELECT 1 FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Player already joined this game']);
            return;
        }

        // 6. Check if game is full
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM game_players WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $count = (int)$stmt->fetch()['count'];

        if ($count >= $game['max_players']) {
            http_response_code(400);
            echo json_encode(['error' => 'Game is full']);
            return;
        }

        // 7. Insert player into game
        $stmt = $db->prepare(
            'INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, :turn_order)'
        );
        $stmt->execute([
            ':game_id'   => $game_id, 
            ':player_id' => $player_id, 
            ':turn_order' => $count
        ]);

        http_response_code(200);
        echo json_encode([
            'message'    => 'Successfully joined game',
            'game_id'    => $game_id,
            'player_id'  => $player_id,
            'turn_order' => $count
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// GET /games/{gameId}
function getGame(int $game_id): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        $stmt = $db->prepare(
            'SELECT p.player_id, p.display_name, gp.turn_order, gp.is_defeated
             FROM game_players gp
             JOIN players p ON p.player_id = gp.player_id
             WHERE gp.game_id = :game_id
             ORDER BY gp.turn_order ASC'
        );
        $stmt->execute([':game_id' => $game_id]);
        $players = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode([
            'gameId'           => $game['game_id'],
            'status'           => $game['status'],
            'gridSize'         => (int)$game['grid_size'],
            'maxPlayers'       => (int)$game['max_players'],
            'currentTurnIndex' => (int)$game['current_turn_index'],
            'players'          => array_map(fn($p) => [
                'playerId'    => $p['player_id'],
                'displayName' => $p['display_name'],
                'turnOrder'   => (int)$p['turn_order'],
                'isDefeated'  => (bool)$p['is_defeated']
            ], $players)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

function checkTestMode(): bool {
    // Check if TEST_MODE is enabled (e.g., via environment variable)
    if (getenv('TEST_MODE') !== 'true') {
        http_response_code(403);
        echo json_encode(['error' => 'Test mode is disabled']);
        return false;
    }

    $header = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';
    if ($header !== 'clemson-test-2026') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: invalid or missing X-Test-Password header']);
        return false;
    }
    return true;
}

// POST /test/games/{gameId}/ships
function testPlaceShips(int $gameId): void {
    if (!checkTestMode()) return;

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($body['playerId']) || empty($body['ships'])) {
        http_response_code(400);
        echo json_encode(['error' => 'playerId and ships are required']);
        return;
    }

    $playerId = trim($body['playerId']);
    $ships    = $body['ships'];

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        if ($game['status'] !== 'waiting') {
            http_response_code(400);
            echo json_encode(['error' => 'Ships can only be placed before game starts']);
            return;
        }

        $stmt = $db->prepare('SELECT 1 FROM game_players WHERE game_id = :gameId AND player_id = :playerId');
        $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'Player is not in this game']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $occupied = [];

        $db->beginTransaction();

        foreach ($ships as $ship) {
            if (empty($ship['coordinates'])) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Each ship must have coordinates']);
                return;
            }

            foreach ($ship['coordinates'] as $coord) {
                $row = $coord[0];
                $col = $coord[1];

                if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => "Coordinate [$row,$col] is out of bounds"]);
                    return;
                }

                $key = "$row,$col";
                if (in_array($key, $occupied)) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => "Coordinate [$row,$col] overlaps with another ship"]);
                    return;
                }

                $occupied[] = $key;

                $stmt = $db->prepare(
                    'INSERT INTO ships (game_id, player_id, row, col)
                     VALUES (:gameId, :playerId, :row, :col)
                     ON CONFLICT (game_id, player_id, row, col) DO NOTHING'
                );
                $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId, ':row' => $row, ':col' => $col]);
            }
        }

        $stmt = $db->prepare(
            'UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gameId AND player_id = :playerId'
        );
        $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['message' => 'Ships placed successfully']);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// GET /test/games/{gameId}/board
function testGetBoard(int $game_id, int $player_id): void {
    if (!checkTestMode()) return;

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        $stmt = $db->prepare('SELECT row, col, is_hit FROM ships WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        $ships = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT row, col, result FROM moves WHERE game_id = :game_id AND player_id != :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        $moves = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode([
            'game_id'   => $game_id,
            'player_id' => $player_id,
            'ships'    => array_map(fn($s) => ['row' => (int)$s['row'], 'col' => (int)$s['col'], 'isHit' => (bool)$s['is_hit']], $ships),
            'moves'    => array_map(fn($m) => ['row' => (int)$m['row'], 'col' => (int)$m['col'], 'result' => $m['result']], $moves)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /test/games/{gameId}/reset
function testResetGame(int $gameId): void {
    if (!checkTestMode()) return;

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        $db->beginTransaction();

        $db->prepare('DELETE FROM ships WHERE game_id = :gameId')->execute([':gameId' => $gameId]);
        $db->prepare('DELETE FROM moves WHERE game_id = :gameId')->execute([':gameId' => $gameId]);
        $db->prepare("UPDATE games SET status = 'waiting', current_turn_index = 0, winner_id = NULL WHERE game_id = :gameId")->execute([':gameId' => $gameId]);
        $db->prepare('UPDATE game_players SET has_placed_ships = FALSE, is_defeated = FALSE WHERE game_id = :gameId')->execute([':gameId' => $gameId]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['message' => 'Game reset successfully']);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /test/games/{gameId}/set-turn
function testSetTurn(int $gameId): void {
    if (!checkTestMode()) return;

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($body['playerId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'playerId is required']);
        return;
    }

    $playerId = trim($body['playerId']);

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT turn_order FROM game_players WHERE game_id = :gameId AND player_id = :playerId');
        $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found in this game']);
            return;
        }

        $db->prepare('UPDATE games SET current_turn_index = :turnIndex WHERE game_id = :gameId')
           ->execute([':turnIndex' => $row['turn_order'], ':gameId' => $gameId]);

        http_response_code(200);
        echo json_encode(['message' => 'Turn set successfully', 'playerId' => $playerId, 'turnIndex' => (int)$row['turn_order']]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /api/reset
function resetSystem(): void {
    try {
        $db = getDB();
        $db->exec('TRUNCATE games, players, moves, game_players CASCADE');
        
        http_response_code(200);
        echo json_encode(['status' => 'reset']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
}

// GET /api/games/{id}/moves
function getGameMoves(int $gameId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT player_id, row, col, result, created_at 
             FROM moves 
             WHERE game_id = :gameId 
             ORDER BY created_at ASC'
        );
        $stmt->execute([':gameId' => $gameId]);
        $moves = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($moves);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

function placeShips(int $game_id): void {
    testPlaceShips($game_id); 
}