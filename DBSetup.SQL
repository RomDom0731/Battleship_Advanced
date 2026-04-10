-- players: Updated to use 'username' as the canonical name per contract
CREATE TABLE players (
    player_id SERIAL PRIMARY KEY,
    username VARCHAR(255) UNIQUE NOT NULL, -- Renamed from display_name
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_games INT DEFAULT 0,
    total_wins INT DEFAULT 0,
    total_losses INT DEFAULT 0,
    total_moves INT DEFAULT 0,
    total_hits INT DEFAULT 0
);

-- games: Updated current_turn_player_id and status enum values
CREATE TABLE games (
    game_id SERIAL PRIMARY KEY,
    grid_size INT NOT NULL CHECK (grid_size >= 5 AND grid_size <= 15),
    max_players INT NOT NULL CHECK (max_players >= 2 AND max_players <= 10), -- Updated bounds
    current_turn_player_id INT REFERENCES players(player_id) DEFAULT NULL, -- Renamed from current_turn_index
    status VARCHAR(20) DEFAULT 'waiting_setup', -- Default changed to waiting_setup
    winner_id INT REFERENCES players(player_id) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT valid_status CHECK (status IN ('waiting_setup', 'playing', 'finished')) -- Enforce strict enum
);

-- game_players: Link table between players and specific game instances
CREATE TABLE game_players (
    game_id INT REFERENCES games(game_id) ON DELETE CASCADE,
    player_id INT REFERENCES players(player_id),
    turn_order INT NOT NULL,
    has_placed_ships BOOLEAN DEFAULT FALSE,
    is_defeated BOOLEAN DEFAULT FALSE,
    PRIMARY KEY (game_id, player_id)
);

-- ships: Individual ship cells; uniqueness prevents overlapping placements
CREATE TABLE ships (
    ship_id SERIAL PRIMARY KEY,
    game_id INT REFERENCES games(game_id) ON DELETE CASCADE,
    player_id INT REFERENCES players(player_id),
    row INT NOT NULL,
    col INT NOT NULL,
    is_hit BOOLEAN DEFAULT FALSE,
    UNIQUE(game_id, player_id, row, col)
);

-- moves: Log of all shots fired; used for accuracy and history endpoints
CREATE TABLE moves (
    move_id SERIAL PRIMARY KEY,
    game_id INT REFERENCES games(game_id) ON DELETE CASCADE,
    player_id INT REFERENCES players(player_id),
    row INT NOT NULL,
    col INT NOT NULL,
    result VARCHAR(10) CHECK (result IN ('hit', 'miss')), -- Enforce hit/miss
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);