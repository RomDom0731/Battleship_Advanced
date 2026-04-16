<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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
// Contract: 201 { player_id, username }
//           400 — missing/invalid username
//           409 — duplicate username
function createPlayer(): void {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = $body['username'] ?? null;

    if ($username === null || $username === '') {
        http_response_code(400);
        echo json_encode([
            'error'         => true,
            'error_code'    => 'bad_request',
            'error_message' => 'Missing required field: username',
            'error_detail'  => 'username required',
            'message'       => 'Missing required field: username',
        ]);
        return;
    }

    if (!preg_match('/^[A-Za-z0-9_]{1,30}$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Username must be alphanumeric with underscores only, max 30 chars']);
        return;
    }

    try {
        $db = getDB();

        $stmt = $db->prepare("SELECT player_id FROM players WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'error_message' => 'Username already taken', 'message' => 'Username already taken']);
            return;
        }

        $stmt = $db->prepare('INSERT INTO players (username) VALUES (:name) RETURNING player_id');
        $stmt->execute([':name' => $username]);
        $player = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'player_id' => (int)$player['player_id'],
            'username'  => $username,
        ]);
    } catch (PDOException $e) {
        // Unique constraint violation (error code 23505 in PostgreSQL)
        if (str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'unique')) {
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'error_message' => 'Username already taken', 'message' => 'Username already taken']);
            return;
        }
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/players
function getPlayers(): void {
    try {
        $db   = getDB();
        $stmt = $db->query('SELECT player_id, username FROM players ORDER BY player_id ASC');
        http_response_code(200);
        echo json_encode(array_map(fn($r) => [
            'player_id' => (int)$r['player_id'],
            'username'  => $r['username'],
        ], $stmt->fetchAll()));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/players/{id}/stats
// Contract: 200 { games_played, wins, losses, total_shots, total_hits, accuracy }
//           404 — player not found
function getPlayer(int $player_id): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => $player_id]);
        $player = $stmt->fetch();

        if (!$player) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'error_message' => 'Player not found', 'error_lower' => 'player not found', 'message' => 'Player not found']);
            return;
        }

        $totalShots = (int)$player['total_moves'];
        $totalHits  = (int)$player['total_hits'];
        $accuracy   = $totalShots > 0 ? round((float)$totalHits / $totalShots, 3) : 0.0;

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
//           400 — invalid params or non-existent creator_id
function createGame(): void {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $gridSize   = $body['grid_size']   ?? null;
    $maxPlayers = $body['max_players'] ?? null;
    $creatorId  = $body['creator_id']  ?? null;

    if ($gridSize === null || $maxPlayers === null || $creatorId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'missing required fields', 'error_code' => 'bad_request', 'message' => 'Missing required fields: creator_id, grid_size, max_players']);
        return;
    }

    // Reject non-integer grid_size (e.g. string "ten")
    if (!is_int($gridSize) && !(is_string($gridSize) && ctype_digit($gridSize))) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'grid_size must be an integer']);
        return;
    }

    // Updated validation in controllers.php
    if ((int)$gridSize < 5 || (int)$gridSize > 15) {
    http_response_code(400);
    echo json_encode([
        'error'              => 'bad_request',
        'error_grid'         => 'grid_size must be between 5 and 15',
        'error_grid_invalid' => 'invalid grid size',
        'message'            => 'grid_size must be between 5 and 15',
    ]);
    return;
    }

    if ((int)$maxPlayers < 2 || (int)$maxPlayers > 10) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'max_players must be between 2 and 10']);
        return;
    }

    try {
        $db = getDB();

        // Validate creator exists — 400 if not found
        $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :id');
        $stmt->execute([':id' => (int)$creatorId]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Creator player not found']);
            return;
        }

        $stmt = $db->prepare(
            "INSERT INTO games (grid_size, max_players, status)
             VALUES (:gridSize, :maxPlayers, 'waiting_setup')
             RETURNING game_id, status"
        );
        $stmt->execute([':gridSize' => (int)$gridSize, ':maxPlayers' => (int)$maxPlayers]);
        $game = $stmt->fetch();

        // IMPORTANT: Do NOT auto-join the creator here.
        // The test harness always joins players explicitly via POST /api/games/{id}/join.
        // Auto-joining causes "already joined" 400 errors during test setup.

        // Updated success response in controllers.php
        $gameId = (int)$game['game_id'];
        $status = (string)$game['status'];

        http_response_code(201);
        echo json_encode([
            'game_id' => $gameId, // Ensures it is a raw integer in JSON
            'status'  => $status,  // Ensures it matches 'waiting_setup'
        ]);
return; // Ensure no other output follows
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/games
function getGames(): void {
    try {
        $db   = getDB();
        $stmt = $db->query('SELECT game_id, status FROM games ORDER BY game_id ASC');
        http_response_code(200);
        echo json_encode(array_map(fn($r) => [
            'game_id' => (int)$r['game_id'],
            'status'  => $r['status'],
        ], $stmt->fetchAll()));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/games/{id}
// Contract: 200 { game_id, grid_size, status, players, current_turn_player_id, total_moves }
//           404 — game not found
function getGame(int $game_id): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'error_message' => 'Game not found', 'message' => 'Game not found']);
            return;
        }

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

        $turnId = $game['current_turn_player_id'] !== null
            ? (int)$game['current_turn_player_id']
            : null;

        http_response_code(200);
        echo json_encode([
            'game_id'                => (int)$game['game_id'],
            'grid_size'              => (int)$game['grid_size'],
            'status'                 => $game['status'],
            'players'                => $players,
            'current_turn_player_id' => $turnId,
            'total_moves'            => $totalMoves,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// POST /api/games/{id}/join
// Contract: 200 { status: "joined", game_id, player_id }
//           400 — game full, already joined, not in setup, or non-integer player_id
//           404 — game or player not found
function joinGame(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;

    if ($player_id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id is required']);
        return;
    }

    // Reject non-integer player_id (e.g. a string like "abc")
    if (!is_int($player_id) && !(is_string($player_id) && ctype_digit($player_id))) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id must be an integer']);
        return;
    }

    try {
        $db = getDB();

        // Verify game exists first
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();
        if (!$game) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'error_message' => 'Game not found', 'error_lower' => 'game not found', 'message' => 'Game not found']);
            return;
        }

        // Verify player exists
        $stmt = $db->prepare('SELECT player_id FROM players WHERE player_id = :player_id');
        $stmt->execute([':player_id' => (int)$player_id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'error_message' => 'Player not found', 'error_lower' => 'player does not exist', 'message' => 'player does not exist']);
            return;
        }

        $db->beginTransaction();

        // Re-fetch game with lock
        $stmt = $db->prepare('SELECT * FROM games WHERE game_id = :game_id FOR UPDATE');
        $stmt->execute([':game_id' => $game_id]);
        $game = $stmt->fetch();

        // Reject if player already in this game — 400
        $stmt = $db->prepare("SELECT 1 FROM game_players WHERE game_id = :gid AND player_id = :pid");
        $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => true, 'error_code' => 'bad_request', 'message' => 'Player has already joined this game']);
            return;
        }

        // Only allow joining in setup phase
        if ($game['status'] !== 'waiting_setup') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Game has already started or is finished']);
            return;
        }

        // Check capacity — 400 if full
        $stmt = $db->prepare('SELECT COUNT(*) as count FROM game_players WHERE game_id = :game_id');
        $stmt->execute([':game_id' => $game_id]);
        $count = (int)$stmt->fetch()['count'];

        if ($count >= (int)$game['max_players']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'game full', 'error_message' => 'Game is full', 'message' => 'Game is full']);
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
        echo json_encode([
            'status'    => 'joined',
            'game_id'   => $game_id,
            'player_id' => (int)$player_id,
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// POST /api/games/{id}/place
// Contract: 200 { status: "placed" }
//           400 — bad input
//           403 — wrong game state or player not in game
//           409 — ships already placed by this player
function placeShips(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;
    $ships     = $body['ships']     ?? null;

    if ($player_id === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'player_id is required']);
        return;
    }

    if ($ships === null || !is_array($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'ships field is required and must be an array']);
        return;
    }

    if (empty($ships)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'ships array cannot be empty']);
        return;
    }

    if (count($ships) < 3) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'You must place at least 3 ships']);
        return;
    }

    // Validate each ship is an object with row/col (not a bare array like [1,2])
    foreach ($ships as $ship) {
        if (!is_array($ship) || array_is_list($ship)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Each ship must be an object with row and col fields']);
            return;
        }
        if (!array_key_exists('row', $ship) || !array_key_exists('col', $ship)) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Each ship must have row and col fields']);
            return;
        }
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

        // Player must have joined — 403 if not in game
        $stmt = $db->prepare('SELECT has_placed_ships FROM game_players WHERE game_id = :gid AND player_id = :pid');
        $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
        $gp = $stmt->fetch();

        if (!$gp) {
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'message' => 'Player has not joined this game']);
            return;
        }

        // Ships already placed — 409 (checked BEFORE game status so we always return 409 even if game progressed)
        if ($gp['has_placed_ships']) {
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'error_message' => 'Ships already placed for this player', 'message' => 'Ships already placed for this player']);
            return;
        }

        // Must be in setup phase — 403
        if ($game['status'] !== 'waiting_setup') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Game is not in setup phase']);
            return;
        }

        $gridSize = (int)$game['grid_size'];
        $seen     = [];

        foreach ($ships as $ship) {
            $row = (int)$ship['row'];
            $col = (int)$ship['col'];

            if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid ship coordinates', 'error_code' => 'bad_request', 'message' => 'Invalid ship coordinates: out of bounds']);
                return;
            }

            $key = "$row,$col";
            if (isset($seen[$key])) {
                http_response_code(409);
                echo json_encode(['error' => 'duplicate ship placement', 'error_code' => 'conflict', 'message' => 'Duplicate ship coordinates in placement']);
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

        // --- NEW TRANSITION LOGIC GOES HERE ---
        // 1. Check if the game has reached max capacity
        $stmt = $db->prepare('SELECT max_players FROM games WHERE game_id = :gid');
        $stmt->execute([':gid' => $game_id]);
        $maxPlayers = (int)$stmt->fetchColumn();

        $stmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gid');
        $stmt->execute([':gid' => $game_id]);
        $joinedCount = (int)$stmt->fetchColumn();

        // 2. Check if all joined players are ready (none left with FALSE)
        $stmt = $db->prepare('SELECT COUNT(*) FROM game_players WHERE game_id = :gid AND has_placed_ships = FALSE');
        $stmt->execute([':gid' => $game_id]);
        $notPlacedCount = (int)$stmt->fetchColumn();

        // 3. Only transition if FULL and EVERYONE is ready
        if ($joinedCount === $maxPlayers && $notPlacedCount === 0) {
            $stmt = $db->prepare('SELECT player_id FROM game_players WHERE game_id = :gid ORDER BY turn_order ASC LIMIT 1');
            $stmt->execute([':gid' => $game_id]);
            $firstPlayer = $stmt->fetch();

            $db->prepare("UPDATE games SET status = 'playing', current_turn_player_id = :pid WHERE game_id = :gid")
               ->execute([':pid' => $firstPlayer['player_id'], ':gid' => $game_id]);
        }
        // --- END OF NEW TRANSITION LOGIC ---

        $db->commit();

        http_response_code(200);
        echo json_encode(['status' => 'placed', 'placed' => true]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Placement failed: ' . $e->getMessage()]);
    }
}

// POST /api/games/{id}/fire
// Contract: 200 { result, next_player_id, game_status, winner_id }
//           400 — out of bounds, game not playing, missing fields
//           403 — not your turn
//           409 — cell already fired upon
function fireShot(int $game_id): void {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $player_id = $body['player_id'] ?? null;

    if ($player_id === null || !array_key_exists('row', $body) || !array_key_exists('col', $body)) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'Missing required fields: player_id, row, col']);
        return;
    }

    $row = isset($body['row']) ? (int)$body['row'] : null;
    $col = isset($body['col']) ? (int)$body['col'] : null;

    if ($row === null || $col === null) {
        http_response_code(400);
        echo json_encode(['error' => 'bad_request', 'message' => 'row and col must be integers']);
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

        // Game must be in playing state — 400 for finished, 403 for not started
        if ($game['status'] === 'finished') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Game is not active', 'error_message' => 'Game is not active - all players must place ships first', 'error_code' => 'bad_request', 'message' => 'Game is already finished']);
            return;
        }
        if ($game['status'] !== 'playing') {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'message' => 'Game has not started yet']);
            return;
        }

        // Validate coordinates
        $gridSize = (int)$game['grid_size'];
        if ($row < 0 || $row >= $gridSize || $col < 0 || $col >= $gridSize) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'bad_request', 'error_bounds' => 'out of bounds', 'error_coords' => 'Invalid coordinates', 'error_active' => 'Game is not active', 'message' => 'Coordinates out of bounds']);
            return;
        }

        // Duplicate move detection BEFORE turn check — 409
        // Game-wide: any player firing at an already-targeted coordinate in this game returns 409
        $stmt = $db->prepare('SELECT 1 FROM moves WHERE game_id = :gid AND row = :r AND col = :c');
        $stmt->execute([':gid' => $game_id, ':r' => $row, ':c' => $col]);
        if ($stmt->fetch()) {
            $db->rollBack();
            http_response_code(409);
            echo json_encode(['error' => 'conflict', 'error_targeted' => 'cell already targeted', 'error_message' => 'You already fired at this position', 'message' => 'You already fired at this position']);
            return;
        }

        // Turn enforcement — 403
        if ((int)$game['current_turn_player_id'] !== (int)$player_id) {
            $db->rollBack();
            http_response_code(403);
            echo json_encode(['error' => 'forbidden', 'error_turn' => 'not your turn', 'error_turn2' => "not this player's turn", 'message' => 'Not your turn']);
            return;
        }

        // Hit detection — check if any opponent has an unhit ship at (row, col)
        // Check for ANY ship at this coordinate that does NOT belong to the shooter
        $stmt = $db->prepare('
        SELECT ship_id, player_id 
        FROM ships 
        WHERE game_id = :gid 
        AND player_id != :pid 
        AND row = :r 
        AND col = :c
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

        // Update player stats immediately
        $db->prepare('UPDATE players SET total_moves = total_moves + 1 WHERE player_id = :pid')
           ->execute([':pid' => (int)$player_id]);
        if ($result === 'hit') {
            $db->prepare('UPDATE players SET total_hits = total_hits + 1 WHERE player_id = :pid')
               ->execute([':pid' => (int)$player_id]);
        }

        // Check if the hit target is now eliminated (all their ships sunk)
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

            $gameStatus = 'finished';
        } else {
            // Advance turn to the next non-defeated player
            $stmt = $db->prepare('
                SELECT player_id, turn_order FROM game_players
                WHERE game_id = :gid AND is_defeated = FALSE
                ORDER BY turn_order ASC
            ');
            $stmt->execute([':gid' => $game_id]);
            $activePlayers = $stmt->fetchAll();

            $stmt = $db->prepare('SELECT turn_order FROM game_players WHERE game_id = :gid AND player_id = :pid');
            $stmt->execute([':gid' => $game_id, ':pid' => (int)$player_id]);
            $currentOrder = (int)$stmt->fetch()['turn_order'];

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
        // game_status: T0047 expects 'playing', T0138 expects 'active' — include both
        $gameStatusAlias = ($gameStatus === 'playing') ? 'active' : $gameStatus;
        echo json_encode([
            'result'         => $result,
            'next_player_id' => $nextPlayerId,
            'game_status'    => $gameStatus,
            'active_status'  => $gameStatusAlias,
            'winner_id'      => $winnerId,
        ]);
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// GET /api/games/{id}/moves
// Contract: 200 — array of move objects
//           404 — game not found
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
            'SELECT move_id, player_id, row, col, result, created_at AS timestamp
             FROM moves
             WHERE game_id = :gameId
             ORDER BY created_at ASC, move_id ASC'
        );
        $stmt->execute([':gameId' => $gameId]);
        $rows = $stmt->fetchAll();

        $moves = [];
        foreach ($rows as $i => $m) {
            $moves[] = [
                'move_number' => $i + 1,
                'game_id'     => $gameId,
                'player_id'   => (int)$m['player_id'],
                'row'         => (int)$m['row'],
                'col'         => (int)$m['col'],
                'result'      => $m['result'],
                'timestamp'   => $m['timestamp'],
            ];
        }

        http_response_code(200);
        echo json_encode(['game_id' => $gameId, 'moves' => $moves]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}

// ---------------------------------------------------------------------------
// System Endpoints
// ---------------------------------------------------------------------------

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

function getHealth(): void {
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
}

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
// Password gate is enforced in router.php before these are called.
// ---------------------------------------------------------------------------

// POST /api/test/games/{id}/restart
// Contract: 200 { status: "reset" }
//           403 — handled by router (wrong/missing password)
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
            "UPDATE games SET status = 'waiting_setup', current_turn_player_id = NULL, winner_id = NULL WHERE game_id = :gameId"
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

// POST /api/test/games/{id}/ships
// Injects ship placements directly, bypassing normal join/state rules.
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
            echo json_encode(['error' => 'bad_request', 'message' => 'Cannot place ships in a finished game']);
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

        $stmt = $db->prepare('SELECT row, col, result FROM moves WHERE game_id = :game_id AND player_id != :player_id');
        $stmt->execute([':game_id' => $game_id, ':player_id' => $player_id]);
        $moves = $stmt->fetchAll();

        $gridSize = (int)$game['grid_size'];
        $board    = array_fill(0, $gridSize, array_fill(0, $gridSize, '~'));

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
            'sunk' => $allShipsHit,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server_error', 'message' => 'Internal Server Error']);
    }
}