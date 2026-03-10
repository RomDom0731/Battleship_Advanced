CREATE TABLE players (
    player_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    display_name VARCHAR(255) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_games INT DEFAULT 0,
    total_wins INT DEFAULT 0,
    total_losses INT DEFAULT 0,
    total_moves INT DEFAULT 0
);

CREATE TABLE games (
    game_id SERIAL PRIMARY KEY,
    grid_size INT NOT NULL CHECK (grid_size >= 5 AND grid_size <= 15),
    max_players INT NOT NULL CHECK (max_players >= 1 AND max_players <= 4),
    current_turn_index INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'waiting',
    winner_id UUID REFERENCES players(player_id)
);

CREATE TABLE game_players (
    game_id INT REFERENCES games(game_id) ON DELETE CASCADE,
    player_id UUID REFERENCES players(player_id),
    turn_order INT NOT NULL,
    has_placed_ships BOOLEAN DEFAULT FALSE,
    is_defeated BOOLEAN DEFAULT FALSE,
    PRIMARY KEY (game_id, player_id),
    CONSTRAINT max_players_reached CHECK (turn_order < 4)
);

CREATE TABLE ships (
    ship_id SERIAL PRIMARY KEY,
    game_id INT REFERENCES games(game_id),
    player_id UUID REFERENCES players(player_id),
    row INT NOT NULL,
    col INT NOT NULL,
    is_hit BOOLEAN DEFAULT FALSE,
    UNIQUE(game_id, player_id, row, col)
);

CREATE TABLE moves (
    move_id SERIAL PRIMARY KEY,
    game_id INT REFERENCES games(game_id),
    player_id UUID REFERENCES players(player_id),
    row INT NOT NULL,
    col INT NOT NULL,
    result VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);