# Battleship CPSC 3750 Project

### Project Overview: 
A 1-N player version of battleship that makes use of RESTful HTTP API. System has unique player accounts and persistent statistics for players. Games have multiple players, including humans and AIs, and customizable grid sizes.

### Architecture Overview:
Client (HTTP)
     │
     ▼
index.php          ← Entry point; sets headers and boots the app
     │
     ▼
router.php         ← Parses URI segments, dispatches to controller functions
     │
     ▼
controllers.php    ← All business logic: game flow, turn rotation, elimination
     │
     ▼
database.php       ← PDO connection factory (supports DATABASE_URL env var)
     │
     ▼
PostgreSQL         ← Persistent relational store (players, games, ships, moves)

### Database Design: 
There are 5 tables in the database.
Players - stores persistent statistics on players
Games - game instances with grid size, player cap, status of the game, and the current turn
Game_Players - Many to Many table between players and games
Ships - Ship cells in a game
Moves - Move log with coordinate, hit/miss, and timestamp

## API Description

All endpoints are prefixed with `/api` and respond with JSON.

### Player Endpoints
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/players` | Register a new player |
| `GET` | `/api/players/{id}/stats` | Retrieve a player's lifetime statistics |

### Game Endpoints
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/games` | Create a new game (grid size, max players) |
| `POST` | `/api/games/{id}/join` | A player joins an existing game |
| `GET` | `/api/games/{id}` | Get current game state |
| `POST` | `/api/games/{id}/place` | Submit ship placements for a player |
| `POST` | `/api/games/{id}/fire` | Fire a shot at a target cell |
| `GET` | `/api/games/{id}/moves` | Retrieve the full move log |

### System Endpoints
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/reset` | Wipe all data (used by autograder) |

### Test / Autograder Endpoints
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/test/games/{id}/restart` | Reset a game to its initial state |
| `POST` | `/api/test/games/{id}/ships` | Inject ship placements directly |
| `GET` | `/api/test/games/{id}/board/{player_id}` | View internal board state for a player |

## Team Members
Roman Pasqualone - Database schema, API layer
Aryan Kapoor - Game logic, Routing

## AI Tools Used

Claude
ChatGPT
Google Gemini

## Roles Summary

### Team Member 1
Primary responsibility: server-side game engine. Owns the database schema, the `game_players` join table design, turn rotation logic, player elimination, and statistics updates. Leads architecture decisions and ensures the data model correctly handles multiplayer edge cases.

### Team Member 2
Primary responsibility: API surface and infrastructure. Owns the router, request/response contracts, Docker setup, and the test harness endpoints. Leads regression testing strategy and ensures Phase 1 API contracts are preserved through Phases 2 and 3.

### Claude (AI)
Used as an engineering assistant throughout the project — generating boilerplate, reviewing logic for correctness, suggesting edge cases, helping debug SQL, and drafting documentation. All AI output was reviewed and validated by the human engineers before integration.
