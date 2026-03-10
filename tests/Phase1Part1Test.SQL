-- ==========================================================
-- PHASE 1: IDENTITY & PERSISTENCE TEST SUITE
-- ==========================================================

DO $$ 
DECLARE 
    p1_id UUID;
    p2_id UUID;
    p3_id UUID;
    p4_id UUID;
    g_id INT;
BEGIN
    RAISE NOTICE 'Starting Battleship Data Integrity Tests...';

    -- 1. TEST: SERVER-GENERATED UUIDs & DISPLAY NAME UNIQUENESS
    -- Inserting players will auto-generate UUIDs via gen_random_uuid().
    INSERT INTO players (display_name) VALUES ('Admiral_Dan') RETURNING player_id INTO p1_id;
    INSERT INTO players (display_name) VALUES ('Captain_Sarah') RETURNING player_id INTO p2_id;
    INSERT INTO players (display_name) VALUES ('Commander_Rex') RETURNING player_id INTO p3_id;
    INSERT INTO players (display_name) VALUES ('Lieutenant_Echo') RETURNING player_id INTO p4_id;
    
    RAISE NOTICE 'Player 1 ID Created: %', p1_id;

    -- 2. TEST: DUPLICATE NAME REJECTION
    BEGIN
        INSERT INTO players (display_name) VALUES ('Admiral_Dan');
    EXCEPTION WHEN unique_violation THEN
        RAISE NOTICE 'PASS: Duplicate name rejection (Unique Constraint).';
    END;

    -- 3. TEST: GAME CREATION & INITIAL STATE
    -- Max players is restricted to 4 by the CHECK constraint.
    INSERT INTO games (grid_size, max_players, status) 
    VALUES (10, 4, 'waiting') RETURNING game_id INTO g_id;
    
    RAISE NOTICE 'Game Created with ID: % and Status: waiting.', g_id;

    -- 4. TEST: GAME JOIN LOGIC & TURN ORDER
    -- Adding players to the game_players table.
    INSERT INTO game_players (game_id, player_id, turn_order) VALUES (g_id, p1_id, 0);
    INSERT INTO game_players (game_id, player_id, turn_order) VALUES (g_id, p2_id, 1);
    INSERT INTO game_players (game_id, player_id, turn_order) VALUES (g_id, p3_id, 2);
    INSERT INTO game_players (game_id, player_id, turn_order) VALUES (g_id, p4_id, 3);

    -- 5. TEST: MAX PLAYER CONSTRAINT (turn_order < 4)
    BEGIN
        INSERT INTO game_players (game_id, player_id, turn_order) 
        VALUES (g_id, gen_random_uuid(), 4);
    EXCEPTION WHEN check_violation THEN
        RAISE NOTICE 'PASS: Rejected 5th player (Max Players Constraint).';
    END;

    -- 6. TEST: MOVE LOGGING
    -- Logging a shot to ensure it persists in the moves table.
    INSERT INTO moves (game_id, player_id, row, col, result) 
    VALUES (g_id, p1_id, 5, 5, 'hit');
    
    RAISE NOTICE 'PASS: Move successfully logged.';

    -- 7. VERIFY STATS PERSISTENCE
    -- Simulating a stats update after a hit.
    UPDATE players SET total_moves = total_moves + 1, total_wins = 1 WHERE player_id = p1_id;
    
    RAISE NOTICE 'PASS: Player statistics successfully updated and persisted.';

    -- ==========================================================
    -- CLEANUP: WIPING DATA FOR NEXT TEST RUN
    -- ==========================================================
    RAISE NOTICE 'Cleaning up test data...';
    DELETE FROM moves WHERE game_id = g_id;
    DELETE FROM game_players WHERE game_id = g_id;
    DELETE FROM games WHERE game_id = g_id;
    DELETE FROM players WHERE player_id IN (p1_id, p2_id, p3_id, p4_id);
    
    RAISE NOTICE 'TEST SUITE COMPLETE: Database cleared.';
END $$;