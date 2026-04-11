<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Translate internal DB status values to API-facing status strings.
 * DB stores: 'waiting_setup' | 'playing' | 'finished'
 */
function translateStatus(string $status): string {
    return match($status) {
        'active'  => 'playing',
        'waiting' => 'waiting_setup',
        default   => $status,
    };
}

// ---------------------------------------------------------------------------
// Player Endpoints
// ---------------------------------------------------------------------------

// POST /api/players
// Contract: 201 { player_id }
//           400 { error: "bad_request", message: ... }  — missing/invalid username
//           409 { error: "conflict",    message: ... }  — duplicate username
function createPlayer(): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = $body['username'] ?? null;

    // Reject missing or empty username
    if ($username === null || $username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Missing required field: username']);
        return;
    }

    // Enforce alphanumeric + underscores only, 1–30 chars
    if (!preg_match('/^[A-Za-z0-9_]{1,30}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Username must be alphanumeric with underscores only']);
        return;
    }

    try {
        $db = getDB();

        // Check if username already exists
        $stmt = $db->prepare("SELECT player_id FROM players WHERE username = ?");
        $stmt->execute([$username]);
        $existing = $stmt->fetch();

        if ($existing) {
            http_response_code(201);
            echo json_encode(['player_id' => (int)$existing['player_id']]);
            return;
        }


        $stmt = $db->prepare('INSERT INTO players (username) VALUES (:name) RETURNING player_id');
        $stmt->execute([':name' => $username]);
        $player = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'player_id' => (int)$player['player_id'],
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/players/{id}/stats
// Contract: 200 { games_played, wins, losses, total_shots, total_hits, accuracy }
//           404 { error, message }
function getPlayer(int $player_id): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        $player = $stmt->fetch();

        if (!$player) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player does not exist']);
            return;
        }

        $totalShots = (int)$player['total_moves'];
        $totalHits  = (int)$player['total_hits'];
        $accuracy   = $totalShots > 0 ? round($totalHits / $totalShots, 3) : 0.0;

        http_response_code(200);
        echo json_encode([
            'games_played' => (int)$player['total_games'],
            'wins'         => (int)$player['total_wins'],
            'losses'       => (int)$player['total_losses'],
            'total_shots'  => $totalShots,
            'total_hits'   => $totalHits,
            'accuracy'     => $accuracy,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// ---------------------------------------------------------------------------
// Game Endpoints
// ---------------------------------------------------------------------------

// POST /api/games
// Contract: 201 { game_id, status }
//           400 { error: "bad_request", message } — invalid params
//           404 { error: "not_found",   message } — creator not found
function createGame(): void {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $gridSize   = $body['grid_size']   ?? null;
    $maxPlayers = $body['max_players'] ?? null;
    $creatorId  = $body['creator_id']  ?? null;

    if ($gridSize === null || $maxPlayers === null || $creatorId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Missing required fields: creator_id, grid_size, max_players']);
        return;
    }

    if ((int)$gridSize < 5 || (int)$gridSize > 15) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Grid size must be between 5 and 15']);
        return;
    }

    if ((int)$maxPlayers < 2 || (int)$maxPlayers > 10) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'max_players must be between 2 and 10']);
        return;
    }

    try {
        $db = getDB();

        // Validate creator exists
        $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :id');
        $stmt->execute([':id' => (int)$creatorId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Creator player not found']);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO games (grid_size, max_players, status)
             VALUES (:gridSize, :maxPlayers, 'waiting_setup')
             RETURNING game_id, status"
        );
        $stmt->execute([':gridSize' => (int)$gridSize, ':maxPlayers' => (int)$maxPlayers]);
        $game = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'game_id' => (int)$game['game_id'],
            'status'  => $game['status'],
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// POST /api/games/{id}/join
// Contract: 200 { status: "joined" }
//           400 { error: "bad_request", message } — game full or already started
//           404 { error: "not_found",   message } — game or player not found
function joinGame(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;

    if ($player_id === null) {
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
        $stmt->execute([':player_id' => (int)$player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player not found']);
            return;
        }

        $db->beginTransaction();

        // Lock game row for concurrent-safe join
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        // Check if player already in game — 409
        $stmt = $db->prepare("SELECT 1 FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Player already in this game']);
            return;
        }

        // Only allow joining in setup phase — 409 if already started
        if ($game['status'] !== 'waiting_setup') {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Game has already started']);
            return;
        }

        // Check capacity — 409 if full
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM game_players WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $count = (int)$stmt->fetch()['count'];

        if ($count >= (int)$game['max_players']) {
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
            ':player_id'  => (int)$player_id,
            ':turn_order' => $count,
        ]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'joined']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/games/{id}
// Contract: 200 { game_id, grid_size, status, players[{player_id, ships_remaining}], current_turn_player_id, total_moves }
//           404 { error, message }
function getGame(int $game_id): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game does not exist']);
            return;
        }

        // Players with their remaining ship count
        $stmt = $db->prepare('
            SELECT gp.player_id, COUNT(s.ship_id) as ships_left
            FROM game_players gp
            LEFT JOIN ships s ON gp.game_id = s.game_id AND gp.player_id = s.player_id AND s.is_hit = FALSE
            WHERE gp.game_id = :game_id
            GROUP BY gp.player_id, gp.turn_order
            ORDER BY gp.turn_order ASC
        ');
        $stmt->execute([':game_id' => $game_id]);
        $players = array_map(fn($p) => [
            'player_id'       => (int)$p['player_id'],
            'ships_remaining' => (int)$p['ships_left'],
        ], $stmt->fetchAll());

        $stmt = $db->prepare('SELECT COUNT(*) FROM moves WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $totalMoves = (int)$stmt->fetchColumn();

        $status = translateStatus($game['status']);

        $turnId = $game['current_turn_player_id'] !== null
            ? (int)$game['current_turn_player_id']
            : null;

        http_response_code(200);
        echo json_encode([
            'game_id'                => (int)$game['game_id'],
            'grid_size'              => (int)$game['grid_size'],
            'status'                 => $status,
            'players'                => $players,
            'current_turn_player_id' => $turnId,
            'total_moves'            => $totalMoves,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// POST /api/games/{id}/place
// Contract: 200 { status: "placed" }
//           403 — not in setup phase (wrong game state)
//           409 — ships already placed by this player
function placeShips(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;
    $ships     = $body['ships']     ?? [];

    if ($player_id === null || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id and ships are required']);
        return;
    }

    if (count($ships) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'You must place at least 3 ships']);
        return;
    }

    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT grid_size, status FROM games WHERE game_id = :gid');
        $stmt->execute([':gid' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        // Must be in setup phase — 403 per contract
        if ($game['status'] !== 'waiting_setup') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Game is not in setup phase']);
            return;
        }

        // Player must have joined
        $stmt = $db->prepare('SELECT has_placed_ships FROM game_players WHERE game_id = :gid AND player_id = :pid');
        $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
        $gp = $stmt->fetch();

        if (!$gp) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Player has not joined this game']);
            return;
        }

        // Ships already placed — 409 per contract
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
            $db->prepare('INSERT INTO ships (game_id, player_id, row, col) VALUES (:gid, :pid, :r, :c)')
               ->execute([':gid' => $game_id, ':pid' => (int)$player_id, ':r' => $row, ':c' => $col]);
        }

        $db->prepare('UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gid AND player_id = :pid')
           ->execute([':gid' => $game_id, ':pid' => (int)$player_id]);

        // Transition to playing if ALL players have placed ships
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND has_placed_ships = FALSE');
        $stmt->execute([':gid' => $game_id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $db->prepare('SELECT player_id FROM game_players WHERE game_id = :gid ORDER BY turn_order ASC LIMIT 1');
            $stmt->execute([':gid' => $game_id]);
            $firstPlayer = $stmt->fetch();

            $db->prepare("UPDATE games SET status = 'playing', current_turn_player_id = :pid WHERE game_id = :gid")
               ->execute([':pid' => $firstPlayer['player_id'], ':gid' => $game_id]);
        }

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'placed']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Placement failed: ' . $e->getMessage()]);
    }
}

// POST /api/games/{id}/fire
// Contract: 200 { result, next_player_id, game_status, winner_id? }
//           400 — out of bounds OR game not in playing state
//           403 { error: "forbidden", message: "Not your turn" }
//           409 { error: "conflict",  message: "Cell already fired upon" }
function fireShot(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;
    $row       = isset($body['row']) ? (int)$body['row'] : null;
    $col       = isset($body['col']) ? (int)$body['col'] : null;

    if ($player_id === null || $row === null || $col === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Missing required fields: player_id, row, col']);
        return;
    }

    try {
        $db = getDB();
        $db->beginTransaction();

        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :gid FOR UPDATE');
        $stmt->execute([':gid' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        // Game must be in playing state — 400 for finished/setup
        if ($game['status'] !== 'playing') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Game is not in playing state']);
            return;
        }

        // Validate coordinates against grid size
        $gridSize = (int)$game['grid_size'];
        if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Coordinates out of bounds']);
            return;
        }

        // Duplicate move detection — 409 (any player already fired here)
        $stmt = $db->prepare('SELECT 1 FROM moves WHERE game_id = :gid AND row = :r AND col = :c');
        $stmt->execute([':gid' => $game_id, ':r' => $row, ':c' => $col]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'message' => 'Cell already fired upon']);
            return;
        }

        // Turn enforcement — 403
        if ((int)$game['current_turn_player_id'] !== (int)$player_id) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Not your turn']);
            return;
        }

        // Hit detection — check if any opponent has a ship at (row, col)
        $stmt = $db->prepare('
            SELECT s.ship_id, s.player_id
            FROM ships s
            JOIN game_players gp ON gp.game_id = s.game_id AND gp.player_id = s.player_id
            WHERE s.game_id = :gid
              AND s.player_id != :pid
              AND s.row = :r
              AND s.col = :c
              AND s.is_hit = FALSE
        ');
        $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id, ':r' => $row, ':c' => $col]);
        $hitShip = $stmt->fetch();

        $result = $hitShip ? 'hit' : 'miss';

        if ($hitShip) {
            $db->prepare('UPDATE ships SET is_hit = TRUE WHERE ship_id = :sid')
               ->execute([':sid' => $hitShip['ship_id']]);
        }

        // Record the move
        $db->prepare('INSERT INTO moves (game_id, player_id, row, col, result) VALUES (:gid, :pid, :r, :c, :res)')
           ->execute([':gid' => $game_id, ':pid' => (int)$player_id, ':r' => $row, ':c' => $col, ':res' => $result]);

        // Update player stats
        $db->prepare('UPDATE players SET total_moves = total_moves + 1 WHERE player_id = :pid')
           ->execute([':pid' => (int)$player_id]);
        if ($result === 'hit') {
            $db->prepare('UPDATE players SET total_hits = total_hits + 1 WHERE player_id = :pid')
               ->execute([':pid' => (int)$player_id]);
        }

        // Check if the hit owner is now eliminated (all their ships sunk)
        $eliminatedPlayer = null;
        if ($hitShip) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM ships WHERE game_id = :gid AND player_id = :pid AND is_hit = FALSE');
            $stmt->execute([':gid' => $game_id, ':pid' => $hitShip['player_id']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $eliminatedPlayer = (int)$hitShip['player_id'];
                $db->prepare('UPDATE game_players SET is_defeated = TRUE WHERE game_id = :gid AND player_id = :pid')
                   ->execute([':gid' => $game_id, ':pid' => $eliminatedPlayer]);
                $db->prepare('UPDATE players SET total_games = total_games + 1, total_losses = total_losses + 1 WHERE player_id = :pid')
                   ->execute([':pid' => $eliminatedPlayer]);
            }
        }

        // Check if only one active player remains → game over
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND is_defeated = FALSE');
        $stmt->execute([':gid' => $game_id]);
        $activeCount = (int)$stmt->fetchColumn();

        $gameStatus   = 'playing';
        $winnerId     = null;
        $nextPlayerId = null;

        if ($activeCount <= 1) {
            // Game over — find the winner
            $stmt = $db->prepare('SELECT player_id FROM game_players WHERE game_id = :gid AND is_defeated = FALSE LIMIT 1');
            $stmt->execute([':gid' => $game_id]);
            $winnerRow = $stmt->fetch();
            $winnerId  = $winnerRow ? (int)$winnerRow['player_id'] : null;

            $db->prepare("UPDATE games SET status = 'finished', winner_id = :wid, current_turn_player_id = NULL WHERE game_id = :gid")
               ->execute([':wid' => $winnerId, ':gid' => $game_id]);

            if ($winnerId) {
                $db->prepare('UPDATE players SET total_games = total_games + 1, total_wins = total_wins + 1 WHERE player_id = :pid')
                   ->execute([':pid' => $winnerId]);
            }

            $gameStatus   = 'finished';
            $nextPlayerId = null;
        } else {
            // Advance turn to the next non-defeated player
            $stmt = $db->prepare('
                SELECT player_id, turn_order FROM game_players
                WHERE game_id = :gid AND is_defeated = FALSE
                ORDER BY turn_order ASC
            ');
            $stmt->execute([':gid' => $game_id]);
            $activePlayers = $stmt->fetchAll();

            // Find current player's turn_order
            $stmt = $db->prepare('SELECT turn_order FROM game_players WHERE game_id = :gid AND player_id = :pid');
            $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
            $currentOrder = (int)$stmt->fetch()['turn_order'];

            // Pick the next active player by turn_order, wrapping around
            $nextPlayer  = null;
            $firstActive = null;
            foreach ($activePlayers as $ap) {
                if ($firstActive === null) $firstActive = (int)$ap['player_id'];
                if ((int)$ap['turn_order'] > $currentOrder && $nextPlayer === null) {
                    $nextPlayer = (int)$ap['player_id'];
                }
            }
            $nextPlayerId = $nextPlayer ?? $firstActive;

            $db->prepare('UPDATE games SET current_turn_player_id = :pid WHERE game_id = :gid')
               ->execute([':pid' => $nextPlayerId, ':gid' => $game_id]);
        }

        $db->commit();

        http_response_code(200);
        echo json_encode([
            'result'         => $result,
            'next_player_id' => $nextPlayerId,
            'game_status'    => $gameStatus,
            'winner_id'      => $winnerId,
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/games/{id}/moves
// Contract: 200 — plain array of move objects
//           404 { error, message }
function getGameMoves(int $gameId): void {
    try {
        $db = getDB();

        $stmt = $db->prepare('SELECT 1 FROM games WHERE game_id = :gameId');
        $stmt->execute([':gameId' => $gameId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Game not found']);
            return;
        }

        $stmt = $db->prepare(
            'SELECT player_id, row, col, result, created_at AS timestamp
             FROM moves
             WHERE game_id = :gameId
             ORDER BY created_at ASC, move_id ASC'
        );
        $stmt->execute([':gameId' => $gameId]);
        $rows  = $stmt->fetchAll();

        // Build the array with move_number per contract example
        $moves = [];
        foreach ($rows as $i => $m) {
            $moves[] = [
                'move_number' => $i + 1,
                'player_id'   => (int)$m['player_id'],
                'row'         => (int)$m['row'],
                'col'         => (int)$m['col'],
                'result'      => $m['result'],
                'timestamp'   => $m['timestamp'],
            ];
        }

        http_response_code(200);
        echo json_encode($moves);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// ---------------------------------------------------------------------------
// System Endpoints
// ---------------------------------------------------------------------------

// GET / or GET /api — metadata
function getMetadata(): void {
    http_response_code(200);
    echo json_encode([
        'name'         => 'Battleship API',
        'version'      => '2.3.0',
        'spec_version' => '2.3',
        'environment'  => getenv('APP_ENV') ?: 'production',
        'test_mode'    => filter_var(getenv('TEST_MODE'), FILTER_VALIDATE_BOOLEAN),
    ]);
}

// GET /api/health
function getHealth(): void {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
}

// POST /api/reset
function resetSystem(): void {
    try {
        $db = getDB();
        $db->exec('TRUNCATE ships, moves, game_players, games, players RESTART IDENTITY CASCADE');
        http_response_code(200);
        echo json_encode(['status' => 'reset']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------
// Test / Autograder Endpoints
// All routes are password-gated in router.php before reaching these functions.
// ---------------------------------------------------------------------------

// POST /api/test/games/{id}/restart
// Contract: 200 { status: "reset" }
//           403 — handled by router
//           404 — game not found
function testResetGame(int $gameId): void {
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

        $db->prepare(
            "UPDATE games
             SET status = 'waiting_setup', current_turn_player_id = NULL, winner_id = NULL
             WHERE game_id = :gameId"
        )->execute([':gameId' => $gameId]);

        $db->prepare(
            'UPDATE game_players SET has_placed_ships = FALSE, is_defeated = FALSE WHERE game_id = :gameId'
        )->execute([':gameId' => $gameId]);

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'reset']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// POST /api/test/games/{id}/ships — inject ship placements directly (bypasses normal rules)
// Contract: 200 { status: "placed" }
function testPlaceShips(int $gameId): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $playerId = $body['player_id'] ?? null;
    $ships    = $body['ships']     ?? [];

    if ($playerId === null || empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id and ships are required']);
        return;
    }

    if (count($ships) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Must place at least 3 ships']);
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
           ->execute([':gid' => $gameId, ':pid' => (int)$playerId]);

        foreach ($ships as $ship) {
            if (isset($ship['row'], $ship['col'])) {
                $row = (int)$ship['row'];
                $col = (int)$ship['col'];
            } elseif (isset($ship[0], $ship[1])) {
                $row = (int)$ship[0];
                $col = (int)$ship[1];
            } else {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'bad_request', 'message' => 'Invalid ship coordinate format']);
                return;
            }

            if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'bad_request', 'message' => "Coordinate [$row, $col] out of bounds"]);
                return;
            }

            $checkStmt = $db->prepare('SELECT 1 FROM ships WHERE game_id = :gid AND player_id = :pid AND row = :r AND col = :c');
            $checkStmt->execute([':gid' => $gameId, ':pid' => (int)$playerId, ':r' => $row, ':c' => $col]);
            if ($checkStmt->fetch()) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'bad_request', 'message' => "Overlap detected at [$row, $col]"]);
                return;
            }

            $db->prepare('INSERT INTO ships (game_id, player_id, row, col) VALUES (:gameId, :playerId, :row, :col)')
               ->execute([':gameId' => $gameId, ':playerId' => (int)$playerId, ':row' => $row, ':col' => $col]);
        }

        $db->prepare('UPDATE game_players SET has_placed_ships = TRUE WHERE game_id = :gameId AND player_id = :playerId')
           ->execute([':gameId' => $gameId, ':playerId' => (int)$playerId]);

        // Transition to playing if all players have placed
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gameId AND has_placed_ships = FALSE');
        $checkStmt->execute([':gameId' => $gameId]);
        if ((int)$checkStmt->fetchColumn() === 0) {
            $stmt = $db->prepare('SELECT player_id FROM game_players WHERE game_id = :gid ORDER BY turn_order ASC LIMIT 1');
            $stmt->execute([':gid' => $gameId]);
            $firstPlayer = $stmt->fetch();

            $db->prepare("UPDATE games SET status = 'playing', current_turn_player_id = :pid WHERE game_id = :gameId")
               ->execute([':pid' => $firstPlayer['player_id'], ':gameId' => $gameId]);
        }

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'placed']);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/test/games/{id}/board/{player_id}
function testGetBoard(int $game_id, int $player_id): void {
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

        // Moves fired BY opponents AT this player's board
        $stmt = $db->prepare('SELECT row, col, result FROM moves WHERE game_id = :game_id AND player_id != :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        $moves = $stmt->fetchAll();

        $gridSize = (int)$game['grid_size'];

        // Build a 2D board: '~' = empty, 'S' = ship, 'O' = hit, 'X' = miss
        $board = array_fill(0, $gridSize, array_fill(0, $gridSize, '~'));
        foreach ($ships as $ship) {
            $r = (int)$ship['row'];
            $c = (int)$ship['col'];
            $board[$r][$c] = $ship['is_hit'] ? 'O' : 'S';
        }
        foreach ($moves as $move) {
            $r = (int)$move['row'];
            $c = (int)$move['col'];
            if ($move['result'] === 'miss') {
                $board[$r][$c] = 'X';
            }
        }

        $boardStrings = array_map(fn($row) => implode(' ', $row), $board);
        $allShipsHit  = count($ships) > 0 && array_reduce($ships, fn($carry, $s) => $carry && (bool)$s['is_hit'], true);

        http_response_code(200);
        echo json_encode([
            'game_id'   => $game_id,
            'player_id' => $player_id,
            'board'     => $boardStrings,
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
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}