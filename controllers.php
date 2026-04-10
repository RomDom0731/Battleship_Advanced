<?php
declare(strict_types=1);

// POST /players
function createPlayer(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // The contract requires 'username'
    $username = $body['username'] ?? null;

    if ($username === null || !preg_match('/^[A-Za-z0-9_]+$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Username must be alphanumeric with underscores only']);
        return;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT player_id FROM players WHERE display_name = :name');
        $stmt->execute([':name' => $username]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Contract requires 409 for conflicts in some test suites
            http_response_code(409);
            echo json_encode([
                'error' => 'conflict',
                'message' => 'Username already taken',
                'player_id' => (int)$existing['player_id']
            ]);
            return;
        }

        $stmt = $db->prepare('INSERT INTO players (display_name) VALUES (:name) RETURNING player_id');
        $stmt->execute([':name' => $username]);
        $player = $stmt->fetch();

        http_response_code(201); // Must be 201
        echo json_encode(['player_id' => (int)$player['player_id']]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /players/{playerId}/stats
function getPlayer(int $player_id): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        $player = $stmt->fetch();

        if (!$player) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player not found']);
            return;
        }

        $totalShots = (int)$player['total_moves'];
        $totalHits  = (int)$player['total_hits'];
        $accuracy   = $totalShots > 0 ? round($totalHits / $totalShots, 3) : 0.0;

        http_response_code(200);
        echo json_encode([
            'player_id'    => (int)$player['player_id'],
            'username'     => $player['display_name'],
            'games_played' => (int)$player['total_games'],
            'wins'         => (int)$player['total_wins'],
            'losses'       => (int)$player['total_losses'],
            'total_shots'  => $totalShots,
            'total_hits'   => $totalHits,
            'accuracy'     => $accuracy,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /games
function createGame(): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Support both camelCase and snake_case input keys
    $gridSize   = $body['grid_size']   ?? $body['gridSize']   ?? null;
    $maxPlayers = $body['max_players'] ?? $body['maxPlayers'] ?? null;
    $creatorId  = $body['creator_id']  ?? $body['creatorId']  ?? null;

    // Require grid_size and max_players explicitly
    if ($gridSize === null || $maxPlayers === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'missing required fields: grid_size, max_players']);
        return;
    }

    if ((int)$gridSize < 5 || (int)$gridSize > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'grid_size must be between 5 and 15']);
        return;
    }

    if ((int)$maxPlayers < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'max_players must be at least 1']);
        return;
    }

    try {
        $db = getDB();

        // Validate creator exists if provided
        if ($creatorId !== null) {
            $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :id');
            $stmt->execute([':id' => $creatorId]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'not_found', 'message' => 'Creator player not found']);
                return;
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO games (grid_size, max_players, status)
             VALUES (:gridSize, :maxPlayers, 'waiting_setup')
             RETURNING game_id, grid_size, max_players, status, created_at"
        );
        $stmt->execute([':gridSize' => (int)$gridSize, ':maxPlayers' => (int)$maxPlayers]);
        $game = $stmt->fetch();

        // Auto-add creator to game_players with turn_order = 0
        if ($creatorId !== null) {
            $stmt = $db->prepare(
                'INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, 0)'
            );
            $stmt->execute([':game_id' => $game['game_id'], ':player_id' => $creatorId]);
        }

        http_response_code(201);
        echo json_encode([
            'game_id'     => (int)$game['game_id'],
            'grid_size'   => (int)$game['grid_size'],
            'max_players' => (int)$game['max_players'],
            'status'      => $game['status'],   // 'waiting_setup'
            'created_at'  => $game['created_at'],
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /games/{gameId}/join
function joinGame(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? $body['playerId'] ?? null;

    if (!$player_id) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id is required']);
        return;
    }

    try {
        $db = getDB();

        // Verify game exists
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        // Verify player exists
        $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player not found']);
            return;
        }

        // Lock the games row so concurrent joins queue up
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        // Only allow joining during setup phase
        if ($game['status'] !== 'waiting_setup') {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'game already started']);
            return;
        }

        // Check duplicate join inside the lock
        $stmt = $db->prepare('SELECT 1 FROM game_players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Player already in this game']);
            return;
        }

        // Check capacity inside the lock
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM game_players WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $count = (int)$stmt->fetch()['count'];

        if ($count >= $game['max_players']) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Game is full']);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO game_players (game_id, player_id, turn_order) VALUES (:game_id, :player_id, :turn_order)'
        );
        $stmt->execute([
            ':game_id'    => $game_id,
            ':player_id'  => $player_id,
            ':turn_order' => $count,
        ]);

        $db->commit();

        http_response_code(200);
        echo json_encode([
            'status'     => 'joined',
            'game_id'    => $game_id,
            'player_id'  => (int)$player_id,
            'turn_order' => $count,
        ]);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// GET /api/games/{id}
function getGame(int $game_id): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game does not exist']);
            return;
        }

        // Fetch all players and calculate their remaining ships
        // The contract requires a "players" array with "player_id" and "ships_remaining"
        $stmt = $db->prepare('
            SELECT gp.player_id, COUNT(s.ship_id) as ships_left
            FROM game_players gp
            LEFT JOIN ships s ON gp.game_id = s.game_id AND gp.player_id = s.player_id AND s.is_hit = FALSE
            WHERE gp.game_id = :game_id
            GROUP BY gp.player_id
            ORDER BY gp.turn_order ASC
        ');
        $stmt->execute([':game_id' => $game_id]);
        $players = array_map(fn($p) => [
            'player_id' => (int)$p['player_id'],
            'ships_remaining' => (int)$p['ships_left']
        ], $stmt->fetchAll());

        // Calculate total moves for the move counter
        $stmt = $db->prepare('SELECT COUNT(*) FROM moves WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $totalMoves = (int)$stmt->fetchColumn();

        // Standardize the status string
        $status = translateStatus($game['status']);

        // The contract requires current_turn_player_id to be a player ID or null
        // Since current_turn_index in your DB stores the player_id of the active player
        $turnId = ($status === 'playing') ? (int)$game['current_turn_index'] : null;

        http_response_code(200);
        echo json_encode([
            'game_id'                => (int)$game['game_id'],
            'grid_size'              => (int)$game['grid_size'],
            'status'                 => $status,
            'players'                => $players,
            'current_turn_player_id' => $turnId,
            'total_moves'            => $totalMoves
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

/**
 * Translate internal DB status values to API-facing status strings.
 * DB stores: 'waiting_setup', 'playing', 'finished'
 * API exposes: same values, but 'playing' maps from internal 'active' if ever set.
 */
function translateStatus(string $status): string {
    return match($status) {
        'active'       => 'playing',
        'waiting'      => 'waiting_setup',
        default        => $status,
    };
}

// Validate the X-Test-Password header. Returns true on success, false (and sends response) on failure.
function checkTestMode(): bool {
    // Contract expects X-Test-Password header
    $password = $_SERVER['HTTP_X_TEST_PASSWORD'] ?? '';

    if ($password !== 'clemson-test-2026') {
        http_response_code(403);
        echo json_encode([
            'error' => 'forbidden', 
            'message' => 'Invalid test password'
        ]);
        return false;
    }
    return true;
}

// POST /test/games/{gameId}/ships  — inject ships directly (test harness)
function testPlaceShips(int $gameId): void {
    if (!checkTestMode()) return;

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = $body['player_id'] ?? $body['playerId'] ?? null;
    $ships    = $body['ships'] ?? [];

    if (!$playerId || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id and ships are required']);
        return;
    }

    if (count($ships) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'You must place exactly 3 ships']);
        return;
    }

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT grid_size, status FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        if ($game['status'] === 'finished') {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Cannot place ships: game is finished']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $db->beginTransaction();

        // Delete existing ships for idempotency
        $db->prepare('DELETE FROM ships WHERE game_id = :gid AND player_id = :pid')
           ->execute([':gid' => $gameId, ':pid' => $playerId]);

        foreach ($ships as $ship) {
            if (isset($ship['row']) || isset($ship['col'])) {
                $coords = [$ship];
            } else {
                $coords = $ship['coordinates'] ?? [];
            }

            foreach ($coords as $coord) {
                $row = isset($coord[0]) ? (int)$coord[0] : (isset($coord['row']) ? (int)$coord['row'] : null);
                $col = isset($coord[1]) ? (int)$coord[1] : (isset($coord['col']) ? (int)$coord['col'] : null);

                if ($row === null || $col === null || $row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'bad_request', 'message' => "Coordinate [$row, $col] out of bounds"]);
                    return;
                }

                $checkStmt = $db->prepare('SELECT 1 FROM ships WHERE game_id = :gid AND player_id = :pid AND row = :r AND col = :c');
                $checkStmt->execute([':gid' => $gameId, ':pid' => $playerId, ':r' => $row, ':c' => $col]);
                if ($checkStmt->fetch()) {
                    $db->rollBack();
                    http_response_code(400);
                    echo json_encode(['error' => 'bad_request', 'message' => "Overlap detected at [$row, $col]"]);
                    return;
                }

                $db->prepare('INSERT INTO ships (game_id, player_id, row, col) VALUES (:gameId, :playerId, :row, :col)')
                   ->execute([':gameId' => $gameId, ':playerId' => $playerId, ':row' => $row, ':col' => $col]);
            }
        }

        $db->prepare('UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gameId AND player_id = :playerId')
           ->execute([':gameId' => $gameId, ':playerId' => $playerId]);

        // Transition to playing if all players have placed
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gameId AND has_placed_ships = FALSE');
        $checkStmt->execute([':gameId' => $gameId]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            $db->prepare("UPDATE games SET status = 'playing' WHERE game_id = :gameId")
               ->execute([':gameId' => $gameId]);
        }

        $db->commit();
        http_response_code(200);
        echo json_encode(['status' => 'placed', 'message' => 'Ships placed successfully']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
}

// GET /test/games/{gameId}/board/{player_id}
function testGetBoard(int $game_id, int $player_id): void {
    if (!checkTestMode()) return;

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT grid_size FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();
        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
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
            if (!$ship['is_hit']) { $allShipsHit = false; break; }
        }

        http_response_code(200);
        echo json_encode([
            'game_id'   => $game_id,
            'player_id' => $player_id,
            'ships'     => array_map(fn($s) => [
                'row'    => (int)$s['row'],
                'col'    => (int)$s['col'],
                'is_hit' => (bool)$s['is_hit'],
            ], $ships),
            'moves'     => array_map(fn($m) => [
                'row'    => (int)$m['row'],
                'col'    => (int)$m['col'],
                'result' => $m['result'],
            ], $moves),
            'sunk'      => $allShipsHit,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /test/games/{gameId}/restart
function testResetGame(int $gameId): void {
    if (!checkTestMode()) return;

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT 1 FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        $db->beginTransaction();

        $db->prepare('DELETE FROM ships WHERE game_id = :gameId')->execute([':gameId' => $gameId]);
        $db->prepare('DELETE FROM moves WHERE game_id = :gameId')->execute([':gameId' => $gameId]);

        $db->prepare("UPDATE games SET status = 'waiting_setup', current_turn_index = 0, winner_id = NULL WHERE game_id = :gameId")
           ->execute([':gameId' => $gameId]);

        $db->prepare('UPDATE game_players SET has_placed_ships = FALSE, is_defeated = FALSE WHERE game_id = :gameId')
           ->execute([':gameId' => $gameId]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'reset', 'message' => 'Game reset successfully']);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /test/games/{gameId}/set-turn
function testSetTurn(int $gameId): void {
    if (!checkTestMode()) return;

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = $body['player_id'] ?? $body['playerId'] ?? null;

    if (!$playerId) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id is required']);
        return;
    }

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT turn_order FROM game_players WHERE game_id = :gameId AND player_id = :playerId');
        $stmt->execute([':gameId' => $gameId, ':playerId' => $playerId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player not found in this game']);
            return;
        }

        $db->prepare('UPDATE games SET current_turn_index = :turnIndex WHERE game_id = :gameId')
           ->execute([':turnIndex' => $row['turn_order'], ':gameId' => $gameId]);

        http_response_code(200);
        echo json_encode([
            'message'    => 'Turn set successfully',
            'player_id'  => (int)$playerId,
            'turn_index' => (int)$row['turn_order'],
        ]);

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

        // Verify game exists
        $stmt = $db->prepare('SELECT 1 FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        $stmt = $db->prepare(
            'SELECT player_id, row, col, result, created_at
             FROM moves
             WHERE game_id = :gameId
             ORDER BY created_at ASC'
        );
        $stmt->execute([':gameId' => $gameId]);
        $moves = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode([
            'game_id' => $gameId,
            'moves'   => $moves,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error']);
    }
}

// POST /api/games/{id}/place
function placeShips(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? $body['playerId'] ?? null;
    $ships     = $body['ships'] ?? [];

    if (!$player_id || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id and ships are required']);
        return;
    }

    if (count($ships) !== 3) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'You must place exactly 3 ships']);
        return;
    }

    try {
        $db = getDB();

        $stmt = $db->prepare("SELECT grid_size, status, max_players FROM games WHERE game_id = :gid");
        $stmt->execute([':gid' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        if ($game['status'] === 'finished') {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Game is already finished']);
            return;
        }

        // Verify player exists
        $stmt = $db->prepare("SELECT player_id FROM players WHERE player_id = :pid");
        $stmt->execute([':pid' => $player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player not found']);
            return;
        }

        // Player must have joined
        $stmt = $db->prepare("SELECT has_placed_ships FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id]);
        $gp = $stmt->fetch();

        if (!$gp) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player has not joined this game']);
            return;
        }

        if ($gp['has_placed_ships']) {
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Ships already placed for this player']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $seen     = [];

        foreach ($ships as $ship) {
            $row = isset($ship['row']) ? (int)$ship['row'] : null;
            $col = isset($ship['col']) ? (int)$ship['col'] : null;

            if ($row === null || $col === null || $row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                http_response_code(400);
                echo json_encode(['error' => 'bad_request', 'message' => 'Invalid ship coordinates: out of bounds']);
                return;
            }

            $key = "$row,$col";
            if (isset($seen[$key])) {
                http_response_code(400);
                echo json_encode(['error' => 'bad_request', 'message' => 'Duplicate ship coordinates in placement']);
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

        // Transition to playing if all players have placed
        $stmt = $db->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND has_placed_ships = FALSE");
        $stmt->execute([':gid' => $game_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $db->prepare("UPDATE games SET status = 'playing' WHERE game_id = :gid")
               ->execute([':gid' => $game_id]);
        }

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'placed', 'message' => 'ok']);

    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Placement failed: ' . $e->getMessage()]);
    }
}

// POST /api/games/{id}/fire
// POST /api/games/{id}/fire
function fireShot(int $game_id): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;
    $row = isset($body['row']) ? (int)$body['row'] : null;
    $col = isset($body['col']) ? (int)$body['col'] : null;

    if ($player_id === null || $row === null || $col === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Missing required fields']);
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
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        if ($game['status'] === 'finished') {
            $db->rollBack();
            http_response_code(400); // Contract requirement
            echo json_encode(['error' => 'bad_request', 'message' => 'Game is already finished']);
            return;
        }

        // Strict Turn Enforcement
        if ((int)$game['current_turn_index'] !== (int)$player_id) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Not your turn']);
            return;
        }

        // Duplicate Move Detection
        $stmt = $db->prepare("SELECT 1 FROM moves WHERE game_id = :gid AND player_id = :pid AND row = :r AND col = :c");
        $stmt->execute([':gid' => $game_id, ':pid' => $player_id, ':r' => $row, ':c' => $col]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Cell already fired upon']);
            return;
        }

        // ... hit detection and state update logic ...
        $db->commit();
    } catch (Exception $e) {
        if (isset($db)) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error']);
    }
}

// GET / — metadata
function getMetadata(): void {
    http_response_code(200);
    echo json_encode([
        'name'         => 'Battleship API',
        'version'      => '2.3.0',
        'spec_version' => '2.3',
        'environment'  => getenv('APP_ENV') ?: 'production',
        'test_mode'    => filter_var(getenv('TEST_MODE'), FILTER_VALIDATE_BOOLEAN)
    ]);
}

// GET /version
function getVersion(): void {
    http_response_code(200);
    echo json_encode(['version' => '1.0.0']);
}

// GET /health  (also handles /api/health via router)
function getHealth(): void {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
}

function translateStatus(string $status): string {
    return match($status) {
        'active'         => 'playing',
        'waiting_setup'  => 'waiting_setup',
        'waiting'        => 'waiting_setup', // Standardize legacy DB values
        default          => $status,
    };
}