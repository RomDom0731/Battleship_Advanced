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

        if (!in_array($game['status'], ['waiting', 'active'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot place ships: game is finished']);
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

        // Transition game to active if all players have placed ships
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gameId AND has_placed_ships = FALSE');
        $checkStmt->execute([':gameId' => $gameId]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            $db->prepare("UPDATE games SET status = 'active' WHERE game_id = :gameId")
               ->execute([':gameId' => $gameId]);
        }

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
            'game_id'     => $game_id,
            'player_id'   => $player_id,
            'ships'       => array_map(fn($s) => [
                'row'    => (int)$s['row'],
                'col'    => (int)$s['col'],
                'is_hit' => (bool)$s['is_hit']
            ], $ships),
            'moves'       => array_map(fn($m) => [
                'row'    => (int)$m['row'],
                'col'    => (int)$m['col'],
                'result' => $m['result']
            ], $moves),
            'sunk'        => $allShipsHit
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
        $db->exec('TRUNCATE ships, moves, game_players, games, players CASCADE');
        
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
    $player_id = $body['playerId'] ?? $body['player_id'] ?? null;
    $ships = $body['ships'] ?? [];

    if (!$player_id || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'player_id and ships are required']);
        return;
    }

    try {
        $db = getDB();

        // Verify game exists and is not finished
        $stmt = $db->prepare("SELECT grid_size, status, max_players FROM games WHERE game_id = :gid");
        $stmt->execute([':gid' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        if ($game['status'] === 'finished') {
            http_response_code(400);
            echo json_encode(['error' => 'Game is already finished']);
            return;
        }

        // Verify player exists
        $stmt = $db->prepare("SELECT player_id FROM players WHERE player_id = :pid");
        $stmt->execute([':pid' => $player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
            return;
        }

        // Check if player is in game_players; if not, auto-add them
        $stmt = $db->prepare("SELECT has_placed_ships FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $gp = $stmt->fetch();

        // Check if player is in game_players
        $stmt = $db->prepare("SELECT has_placed_ships FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $gp = $stmt->fetch();

        if (!$gp) {
            http_response_code(404);
            echo json_encode(['error' => 'Player has not joined this game']);
            return;
        }

        if ($gp['has_placed_ships']) {
            http_response_code(400);
            echo json_encode(['error' => 'Ships already placed']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $seen = [];

        foreach ($ships as $ship) {
            $row = isset($ship['row']) ? (int)$ship['row'] : null;
            $col = isset($ship['col']) ? (int)$ship['col'] : null;

            if ($row === null || $col === null || $row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                http_response_code(400);
                echo json_encode(['error' => "Coordinate out of bounds"]);
                return;
            }

            $key = "$row,$col";
            if (isset($seen[$key])) {
                http_response_code(400);
                echo json_encode(['error' => "Duplicate coordinate"]);
                return;
            }
            $seen[$key] = true;
        }

        $db->beginTransaction();

        foreach ($ships as $ship) {
            $row = (int)$ship['row'];
            $col = (int)$ship['col'];
            $db->prepare("INSERT INTO ships (game_id, player_id, row, col) VALUES (:gid, :pid, :r, :c)")
               ->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        }

        $db->prepare("UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gid AND player_id = :pid")
           ->execute([':gid' => $game_id, ':pid' => $player_id]);

        // Transition to active if all players have placed
        $stmt = $db->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND has_placed_ships = FALSE");
        $stmt->execute([':gid' => $game_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->prepare("UPDATE games SET status = 'active' WHERE game_id = :gid")->execute([':gid' => $game_id]);
        }

        $db->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Ships placed successfully']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
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

        if (!$game) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Game not found']);
            return;
        }

        if ($game['status'] !== 'active') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Game is not active']);
            return;
        }

        $stmt = $db->prepare("SELECT player_id FROM players WHERE player_id = :pid");
        $stmt->execute([':pid' => $player_id]);
        if (!$stmt->fetch()) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
            return;
        }

        $stmt = $db->prepare("SELECT turn_order FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $pInfo = $stmt->fetch();

        if (!$pInfo) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Player not found in game']);
            return;
        }

        if ($game['current_turn_index'] != $pInfo['turn_order']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => "Not your turn"]); return;
        }

        if ($row < 0 || $row >= (int)$game['grid_size'] || $col < 0 || $col >= (int)$game['grid_size']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Shot out of bounds']);
            return;
        }

        $stmt = $db->prepare("SELECT 1 FROM moves WHERE game_id = :gid AND player_id = :pid AND row = :r AND col = :c");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Cell already targeted']);
            return;
        }

        // Find whose ship was hit (if any) - target is any other player's ship
        $stmt = $db->prepare("SELECT s.ship_id, s.player_id as owner_id FROM ships s WHERE s.game_id = :gid AND s.player_id != :pid AND s.row = :r AND s.col = :c AND s.is_hit = FALSE");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        $ship = $stmt->fetch();

        $result = $ship ? 'hit' : 'miss';
        if ($ship) {
            $db->prepare("UPDATE ships SET is_hit = TRUE WHERE ship_id = :sid")->execute([':sid' => $ship['ship_id']]);
        }

        $stmt = $db->prepare("INSERT INTO moves (game_id, player_id, row, col, result) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$game_id, $player_id, $row, $col, $result]);

        $db->prepare("UPDATE players SET total_moves = total_moves + 1, total_hits = total_hits + " . ($ship ? 1 : 0) . " WHERE player_id = ?")->execute([$player_id]);

        // Check if the hit owner has all ships sunk (defeated)
        $gameStatus = 'active';
        $winnerId = null;

        if ($ship) {
            $ownerId = $ship['owner_id'];
            $stmt = $db->prepare("SELECT COUNT(*) FROM ships WHERE game_id = :gid AND player_id = :oid AND is_hit = FALSE");
            $stmt->execute([':gid' => $game_id, ':oid' => $ownerId]);
            $remainingShips = (int)$stmt->fetchColumn();

            if ($remainingShips === 0) {
                // Owner is defeated
                $db->prepare("UPDATE game_players SET is_defeated = TRUE WHERE game_id = :gid AND player_id = :oid")
                   ->execute([':gid' => $game_id, ':oid' => $ownerId]);

                // Update loser stats
                $db->prepare("UPDATE players SET total_games = total_games + 1, total_losses = total_losses + 1 WHERE player_id = ?")
                   ->execute([$ownerId]);

                // Check how many active players remain
                $stmt = $db->prepare("SELECT player_id FROM game_players WHERE game_id = :gid AND is_defeated = FALSE");
                $stmt->execute([':gid' => $game_id]);
                $survivors = $stmt->fetchAll();

                if (count($survivors) === 1) {
                    // We have a winner!
                    $winnerId = (int)$survivors[0]['player_id'];
                    $gameStatus = 'finished';

                    $db->prepare("UPDATE games SET status = 'finished', winner_id = :wid WHERE game_id = :gid")
                       ->execute([':wid' => $winnerId, ':gid' => $game_id]);

                    // Update winner stats
                    $db->prepare("UPDATE players SET total_games = total_games + 1, total_wins = total_wins + 1 WHERE player_id = ?")
                       ->execute([$winnerId]);
                }
            }
        }

        // Find next player (only if game not finished)
        $nextPlayerId = null;
        $nextTurnIndex = (int)$game['current_turn_index'];

        if ($gameStatus === 'active') {
            $stmt = $db->prepare("SELECT player_id, turn_order FROM game_players WHERE game_id = :gid AND is_defeated = FALSE ORDER BY turn_order ASC");
            $stmt->execute([':gid' => $game_id]);
            $activePlayers = $stmt->fetchAll();

            if (!empty($activePlayers)) {
                $currentTurn = (int)$game['current_turn_index'];
                $nextTurn = null;
                foreach ($activePlayers as $ap) {
                    if ((int)$ap['turn_order'] > $currentTurn) {
                        $nextTurn = $ap;
                        break;
                    }
                }
                if (!$nextTurn) $nextTurn = $activePlayers[0];
                $nextTurnIndex = (int)$nextTurn['turn_order'];
                $nextPlayerId = (int)$nextTurn['player_id'];
            }

            $db->prepare("UPDATE games SET current_turn_index = :nt WHERE game_id = :gid")->execute([':nt' => $nextTurnIndex, ':gid' => $game_id]);
        }

        $db->commit();

        $response = [
            "result" => $result,
            "next_player_id" => $nextPlayerId,
            "game_status" => $gameStatus,
        ];
        if ($winnerId !== null) {
            $response['winner_id'] = $winnerId;
        }
        echo json_encode($response);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}