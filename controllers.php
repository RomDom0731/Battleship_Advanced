<?php
declare(strict_types=1);

// POST /players
function createPlayer(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Support both camelCase and snake_case for player name
    $playerName = $body['playerName'] ?? $body['player_name'] ?? $body['username'] ?? $body['display_name'] ?? null;

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

    // Support both camelCase and snake_case
    $gridSize   = $body['gridSize']   ?? $body['grid_size']   ?? 10;
    $maxPlayers = $body['maxPlayers'] ?? $body['max_players'] ?? 2;
    $creatorId  = $body['creatorId']  ?? $body['creator_id']  ?? null;

    // Validation
    if ((int)$gridSize < 5 || (int)$gridSize > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'gridSize must be between 5 and 15']);
        return;
    }

    if ((int)$maxPlayers < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'maxPlayers must be at least 1']);
        return;
    }

    try {
        $db = getDB();

        // Validate creator exists if provided
        if ($creatorId) {
            $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :id');
            $stmt->execute([':id' => $creatorId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Creator player not found']);
                return;
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO games (grid_size, max_players, status)
             VALUES (:gridSize, :maxPlayers, 'waiting')
             RETURNING game_id, grid_size, max_players, status, created_at"
        );
        $stmt->execute([':gridSize' => $gridSize, ':maxPlayers' => $maxPlayers]);
        $game = $stmt->fetch();

        // Auto-add creator to game_players with turn_order = 0
        if ($creatorId) {
            $stmt = $db->prepare(
                'INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, 0)'
            );
            $stmt->execute([':game_id' => $game['game_id'], ':player_id' => $creatorId]);
        }

        http_response_code(201);
        echo json_encode([
            'game_id'    => (int)$game['game_id'],
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
        
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        // Support both cases for player_id
        $player_id = $body['playerId'] ?? $body['player_id'] ?? null;

        if (!$player_id) {
            http_response_code(400);
            echo json_encode(['error' => 'player_id is required']);
            return;
        }

        if ($game['status'] !== 'waiting') {
            http_response_code(400);
            echo json_encode(['error' => 'Game is no longer accepting players']);
            return;
        }

        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
            return;
        }

        $stmt = $db->prepare('SELECT 1 FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Player already joined this game']);
            return;
        }

        $stmt = $db->prepare('SELECT COUNT(*) as count FROM game_players WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $count = (int)$stmt->fetch()['count'];

        if ($count >= $game['max_players']) {
            http_response_code(400);
            echo json_encode(['error' => 'Game is full']);
            return;
        }

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
            'SELECT COUNT(*) as active_count FROM game_players WHERE game_id = :game_id AND is_defeated = FALSE'
        );
        $stmt->execute([':game_id' => $game_id]);
        $activeCount = (int)$stmt->fetch()['active_count'];

        http_response_code(200);
        echo json_encode([
            'game_id'           => (int)$game['game_id'],
            'grid_size'         => (int)$game['grid_size'],
            'status'            => $game['status'],
            'current_turn_index'=> (int)$game['current_turn_index'],
            'active_players'    => $activeCount,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

function checkTestMode(): bool {
    if (getenv('TEST_MODE') !== 'true') {
        http_response_code(403);
        echo json_encode(['error' => 'Test mode is disabled']);
        return false;
    }

    $testModeHeader = $_SERVER['HTTP_X_TEST_MODE'] ?? $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';

    if ($testModeHeader !== 'clemson-test-2026') {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: invalid or missing security header']);
        return false;
    }
    
    return true;
}

// POST /test/games/{gameId}/ships
function testPlaceShips(int $gameId): void {
    if (!checkTestMode()) return;

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Support both cases for player ID
    $playerId = $body['playerId'] ?? $body['player_id'] ?? null;
    $ships    = $body['ships'] ?? [];

    if (!$playerId || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'playerId and ships are required']);
        return;
    }

    try {
        $db = getDB();
        
        $stmt = $db->prepare('SELECT grid_size, status FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        if ($game['status'] !== 'waiting') {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot place ships: game has already started']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $db->beginTransaction();

        // Delete any existing ships for this player in this game (for idempotency)
        $db->prepare('DELETE FROM ships WHERE game_id = :gid AND player_id = :pid')
           ->execute([':gid' => $gameId, ':pid' => $playerId]);

        foreach ($ships as $ship) {
            // Support flat {row, col} format OR coordinates array format
            if (isset($ship['row']) || isset($ship['col'])) {
                $coords = [$ship];
            } else {
                $coords = $ship['coordinates'] ?? [];
            }
            
            foreach ($coords as $coord) {
                // Support both array [row, col] and object {row, col} formats
                $row = isset($coord[0]) ? (int)$coord[0] : (isset($coord['row']) ? (int)$coord['row'] : null);
                $col = isset($coord[1]) ? (int)$coord[1] : (isset($coord['col']) ? (int)$coord['col'] : null);

                if ($row === null || $col === null || $row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => "Coordinate [$row, $col] out of bounds"]);
                    return;
                }

                $checkStmt = $db->prepare('SELECT 1 FROM ships WHERE game_id = :gid AND player_id = :pid AND row = :r AND col = :c');
                $checkStmt->execute([':gid' => $gameId, ':pid' => $playerId, ':r' => $row, ':c' => $col]);
                if ($checkStmt->fetch()) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => "Overlap detected at [$row, $col]"]);
                    return;
                }

                $stmt = $db->prepare(
                    'INSERT INTO ships (game_id, player_id, row, col) VALUES (:gameId, :playerId, :row, :col)'
                );
                $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId, ':row' => $row, ':col' => $col]);
            }
        }

        $db->prepare('UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gameId AND player_id = :playerId')
           ->execute([':gameId' => $gameId, ':playerId' => $playerId]);

        $db->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Ships placed successfully']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}

// GET /test/games/{gameId}/board
function testGetBoard(int $game_id, int $player_id): void {
    if (!checkTestMode()) return;

    try {
        $db = getDB();
        
        $stmt = $db->prepare('SELECT grid_size FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();
        if (!$game) {
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

        $allShipsHit = count($ships) > 0;
        foreach ($ships as $ship) {
            if (!$ship['is_hit']) {
                $allShipsHit = false;
                break;
            }
        }

        http_response_code(200);
        echo json_encode([
            'game_id'   => $game_id,
            'player_id' => $player_id,
            'ships'     => array_map(fn($s) => [
                'row' => (int)$s['row'], 
                'col' => (int)$s['col'], 
                'isHit' => (bool)$s['is_hit']
            ], $ships),
            'moves'     => array_map(fn($m) => [
                'row' => (int)$m['row'], 
                'col' => (int)$m['col'], 
                'result' => $m['result']
            ], $moves),
            'sunk_status' => $allShipsHit
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

        $stmt = $db->prepare('SELECT 1 FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        $db->beginTransaction();

        $db->prepare('DELETE FROM ships WHERE game_id = :gameId')->execute([':gameId' => $gameId]);
        $db->prepare('DELETE FROM moves WHERE game_id = :gameId')->execute([':gameId' => $gameId]);

        $db->prepare("UPDATE games SET status = 'waiting', current_turn_index = 0, winner_id = NULL WHERE game_id = :gameId")
           ->execute([':gameId' => $gameId]);

        $db->prepare('UPDATE game_players SET has_placed_ships = FALSE, is_defeated = FALSE WHERE game_id = :gameId')
           ->execute([':gameId' => $gameId]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['message' => 'Game reset successfully']);

    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /test/games/{gameId}/set-turn
function testSetTurn(int $gameId): void {
    if (!checkTestMode()) return;

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Support both cases for player ID
    $playerId = $body['playerId'] ?? $body['player_id'] ?? null;

    if (!$playerId) {
        http_response_code(400);
        echo json_encode(['error' => 'playerId is required']);
        return;
    }

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
        echo json_encode(['error' => $e->getMessage()]);
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

// POST /api/games/{id}/place
function placeShips(int $game_id): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Support both cases for player ID
    $player_id = $body['playerId'] ?? $body['player_id'] ?? null;
    $ships = $body['ships'] ?? [];

    if (!$player_id || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'player_id and ships are required']);
        return;
    }

    // Exactly 3 single-cell ships required
    if (count($ships) !== 3) {
        http_response_code(400);
        echo json_encode(['error' => 'Exactly 3 ships are required']);
        return;
    }

    try {
        $db = getDB();
        
        $stmt = $db->prepare("SELECT g.grid_size, gp.has_placed_ships FROM game_players gp JOIN games g ON g.game_id = gp.game_id WHERE gp.game_id = :gid AND gp.player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $status = $stmt->fetch();

        if (!$status) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found in game']);
            return;
        }

        if ($status['has_placed_ships']) {
            http_response_code(400);
            echo json_encode(['error' => 'Ships already placed']);
            return;
        }

        $gridSize = (int)$status['grid_size'];
        $seen = [];

        // Validate all coordinates first
        foreach ($ships as $ship) {
            $row = $ship['row'] ?? null;
            $col = $ship['col'] ?? null;

            if ($row === null || $col === null || $row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                http_response_code(400);
                echo json_encode(['error' => "Coordinate [$row, $col] out of bounds"]);
                return;
            }

            $key = "$row,$col";
            if (isset($seen[$key])) {
                http_response_code(400);
                echo json_encode(['error' => "Duplicate coordinate [$row, $col]"]);
                return;
            }
            $seen[$key] = true;
        }

        $db->beginTransaction();
        
        foreach ($ships as $ship) {
            $row = (int)$ship['row'];
            $col = (int)$ship['col'];
            
            $stmt = $db->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (:gid, :pid, :r, :c)");
            $stmt->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        }

        $db->prepare("UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gid AND player_id = :pid")->execute([':gid' => $game_id, ':pid' => $player_id]);
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND has_placed_ships = FALSE");
        $stmt->execute([':gid' => $game_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->prepare("UPDATE games SET status = 'active' WHERE game_id = :gid")->execute([':gid' => $game_id]);
        }

        $db->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Ships placed successfully']);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Placement failed: ' . $e->getMessage()]);
    }
}

// POST /api/games/{id}/fire
function fireShot(int $game_id): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Support both cases for player ID
    $player_id = $body['playerId'] ?? $body['player_id'] ?? null;
    $row = (int)($body['row'] ?? -1);
    $col = (int)($body['col'] ?? -1);

    if (!$player_id) {
        http_response_code(400);
        echo json_encode(['error' => 'player_id is required']);
        return;
    }

    try {
        $db = getDB();
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT * FROM games WHERE game_id = :gid FOR UPDATE");
        $stmt->execute([':gid' => $game_id]);
        $game = $stmt->fetch();

        $stmt = $db->prepare("SELECT turn_order FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $pInfo = $stmt->fetch();

        if ($game['current_turn_index'] != $pInfo['turn_order']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => "Not your turn"]); return;
        }

        $stmt = $db->prepare("SELECT ship_id FROM ships WHERE game_id = :gid AND player_id != :pid AND row = :r AND col = :c");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        $ship = $stmt->fetch();

        $result = $ship ? 'hit' : 'miss';
        if ($ship) {
            $db->prepare("UPDATE ships SET is_hit = TRUE WHERE ship_id = :sid")->execute([':sid' => $ship['ship_id']]);
        }

        $stmt = $db->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$game_id, $player_id, $row, $col, $result]);

        $db->prepare("UPDATE players SET total_moves = total_moves + 1, total_hits = total_hits + " . ($ship ? 1 : 0) . " WHERE player_id = ?")->execute([$player_id]);

        $nextTurn = ($game['current_turn_index'] + 1) % $game['max_players'];
        $db->prepare("UPDATE games SET current_turn_index = :nt WHERE game_id = :gid")->execute([':nt' => $nextTurn, ':gid' => $game_id]);

        $db->commit();
        echo json_encode([
            "result" => $result,
            "next_player_id" => null,
            "game_status" => $game['status']
        ]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}