#!/usr/bin/env python3
"""
Battleship API Regression Test Suite
=====================================
Tests all current endpoints and validates existing behaviour so that
regressions introduced by new feature work (turn enforcement, fake-player
rejection, out-of-bounds, duplicate coord, move logging, game completion,
identity reuse) are caught immediately.

Usage:
    python test_battleship.py                        # default: http://localhost:8080
    python test_battleship.py http://my-server.com   # custom base URL

Requirements:  Python 3.8+, requests  (pip install requests)
Test-mode header: X-Test-Mode: clemson-test-2026  (set in checkTestMode())
"""

import sys
import json
import time
import requests

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
BASE_URL   = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "http://localhost:8080"
TEST_HEADER = {"X-Test-Mode": "clemson-test-2026", "Content-Type": "application/json"}
STD_HEADER  = {"Content-Type": "application/json"}

# ---------------------------------------------------------------------------
# Tiny test-runner helpers
# ---------------------------------------------------------------------------
PASS = "\033[92m PASS\033[0m"
FAIL = "\033[91m FAIL\033[0m"
INFO = "\033[94m INFO\033[0m"

results = {"passed": 0, "failed": 0}


def check(label: str, condition: bool, detail: str = ""):
    if condition:
        print(f"{PASS} {label}")
        results["passed"] += 1
    else:
        print(f"{FAIL} {label}" + (f"  →  {detail}" if detail else ""))
        results["failed"] += 1


def section(title: str):
    print(f"\n{'='*60}\n  {title}\n{'='*60}")


def api(method: str, path: str, body=None, headers=None):
    """Make a request and return (status_code, parsed_json)."""
    h = headers or STD_HEADER
    url = BASE_URL + path
    try:
        r = getattr(requests, method)(url, json=body, headers=h, timeout=10)
        try:
            data = r.json()
        except Exception:
            data = {}
        return r.status_code, data
    except requests.exceptions.ConnectionError as e:
        print(f"  [CONNECTION ERROR] {url} – {e}")
        return 0, {}


def reset():
    """Wipe all data between test groups."""
    status, _ = api("post", "/api/reset")
    assert status == 200, f"Reset failed with status {status}"


# ===========================================================================
# TEST GROUPS
# ===========================================================================

def test_reset():
    section("POST /api/reset")
    status, body = api("post", "/api/reset")
    check("reset returns 200", status == 200)
    check("reset body has 'status' key", "status" in body, str(body))


# ---------------------------------------------------------------------------
def test_create_player():
    section("POST /api/players — createPlayer()")

    # Happy path
    status, body = api("post", "/api/players", {"playerName": "Alice"})
    check("create player returns 201", status == 201, str(body))
    check("response has player_id",   "player_id"   in body, str(body))
    check("response has displayName", "displayName" in body, str(body))
    alice_id = body.get("player_id")

    # Idempotent — same name returns 200 + same id
    status2, body2 = api("post", "/api/players", {"playerName": "Alice"})
    check("duplicate name returns 200",          status2 == 200, str(body2))
    check("duplicate returns same player_id",    body2.get("player_id") == alice_id, str(body2))

    # snake_case alias
    status3, body3 = api("post", "/api/players", {"player_name": "Bob"})
    check("snake_case player_name accepted",     status3 == 201, str(body3))

    # display_name alias
    status4, body4 = api("post", "/api/players", {"display_name": "Carol"})
    check("display_name alias accepted",         status4 == 201, str(body4))

    # Missing name
    status5, body5 = api("post", "/api/players", {})
    check("missing playerName → 400",            status5 == 400, str(body5))

    # Blank name
    status6, body6 = api("post", "/api/players", {"playerName": "   "})
    check("blank playerName → 400",              status6 == 400, str(body6))

    return alice_id


# ---------------------------------------------------------------------------
def test_get_player_stats(player_id: int):
    section("GET /api/players/{id}/stats — getPlayer()")

    status, body = api("get", f"/api/players/{player_id}/stats")
    check("get stats returns 200",          status == 200, str(body))
    check("has games_played field",         "games_played" in body, str(body))
    check("has wins field",                 "wins"         in body, str(body))
    check("has losses field",               "losses"       in body, str(body))
    check("has total_shots field",          "total_shots"  in body, str(body))
    check("has total_hits field",           "total_hits"   in body, str(body))
    check("has accuracy field",             "accuracy"     in body, str(body))

    # Non-existent player
    status2, body2 = api("get", "/api/players/999999/stats")
    check("unknown player → 404",           status2 == 404, str(body2))


# ---------------------------------------------------------------------------
def test_create_game():
    section("POST /api/games — createGame()")

    status, body = api("post", "/api/games", {"gridSize": 10, "maxPlayers": 2})
    check("create game returns 201",        status == 201, str(body))
    check("has game_id",                    "game_id"    in body, str(body))
    check("has gridSize",                   "gridSize"   in body, str(body))
    check("has maxPlayers",                 "maxPlayers" in body, str(body))
    check("status is 'waiting'",            body.get("status") == "waiting", str(body))
    game_id = body.get("game_id")

    # grid_size alias
    status2, body2 = api("post", "/api/games", {"grid_size": 8, "max_players": 2})
    check("snake_case aliases accepted",    status2 == 201, str(body2))

    # gridSize too small
    status3, body3 = api("post", "/api/games", {"gridSize": 4})
    check("gridSize 4 → 400",              status3 == 400, str(body3))

    # gridSize too large
    status4, body4 = api("post", "/api/games", {"gridSize": 16})
    check("gridSize 16 → 400",             status4 == 400, str(body4))

    # maxPlayers 0
    status5, body5 = api("post", "/api/games", {"gridSize": 10, "maxPlayers": 0})
    check("maxPlayers 0 → 400",            status5 == 400, str(body5))

    # Creator that doesn't exist
    status6, body6 = api("post", "/api/games", {"gridSize": 10, "maxPlayers": 2, "creatorId": 999999})
    check("fake creatorId → 404",          status6 == 404, str(body6))

    return game_id


# ---------------------------------------------------------------------------
def test_join_game(game_id: int, player1_id: int, player2_id: int):
    section("POST /api/games/{id}/join — joinGame()")

    # Player 1 joins
    status, body = api("post", f"/api/games/{game_id}/join", {"playerId": player1_id})
    check("p1 joins → 200",                status == 200, str(body))
    check("response has turn_order",        "turn_order" in body, str(body))

    # Player 1 double-join
    status2, body2 = api("post", f"/api/games/{game_id}/join", {"playerId": player1_id})
    check("duplicate join → 400",           status2 == 400, str(body2))

    # Player 2 joins
    status3, body3 = api("post", f"/api/games/{game_id}/join", {"playerId": player2_id})
    check("p2 joins → 200",                status3 == 200, str(body3))

    # Fake player
    status4, body4 = api("post", f"/api/games/{game_id}/join", {"playerId": 999999})
    check("fake playerId on join → 404",    status4 == 404, str(body4))

    # Game full — create a third player and try
    _, p3 = api("post", "/api/players", {"playerName": "Dave"})
    status5, body5 = api("post", f"/api/games/{game_id}/join", {"playerId": p3["player_id"]})
    check("join full game → 400",           status5 == 400, str(body5))

    # Join a non-existent game
    status6, body6 = api("post", "/api/games/999999/join", {"playerId": player1_id})
    check("join non-existent game → 404",   status6 == 404, str(body6))

    # Missing player_id body
    status7, body7 = api("post", f"/api/games/{game_id}/join", {})
    check("join without player_id → 400",   status7 == 400, str(body7))


# ---------------------------------------------------------------------------
def test_get_game(game_id: int):
    section("GET /api/games/{id} — getGame()")

    status, body = api("get", f"/api/games/{game_id}")
    check("get game returns 200",           status == 200, str(body))
    check("has game_id",                    "game_id"    in body, str(body))
    check("has grid_size",                  "grid_size"  in body, str(body))
    check("has status",                     "status"     in body, str(body))
    check("has current_turn_index",         "current_turn_index" in body, str(body))
    check("has active_players",             "active_players"     in body, str(body))

    status2, body2 = api("get", "/api/games/999999")
    check("get non-existent game → 404",    status2 == 404, str(body2))


# ---------------------------------------------------------------------------
def test_place_ships(game_id: int, player1_id: int, player2_id: int):
    section("POST /api/games/{id}/place — placeShips()")

    ships_p1 = [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]
    ships_p2 = [{"row": 9, "col": 9}, {"row": 9, "col": 8}, {"row": 9, "col": 7}]

    status, body = api("post", f"/api/games/{game_id}/place",
                       {"playerId": player1_id, "ships": ships_p1})
    check("p1 places ships → 200",          status == 200, str(body))

    # Duplicate place for p1
    status2, body2 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player1_id, "ships": ships_p1})
    check("second place → 400",             status2 == 400, str(body2))

    # Out-of-bounds coordinate (row >= gridSize=10)
    bad_ships = [{"row": 10, "col": 0}]
    status3, body3 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player2_id, "ships": bad_ships})
    check("out-of-bounds coord → 400",      status3 == 400, str(body3))

    # Negative coordinate
    bad_ships2 = [{"row": -1, "col": 0}]
    status4, body4 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player2_id, "ships": bad_ships2})
    check("negative coord → 400",           status4 == 400, str(body4))

    # Duplicate coordinates within same placement
    dup_ships = [{"row": 5, "col": 5}, {"row": 5, "col": 5}]
    status5, body5 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player2_id, "ships": dup_ships})
    check("duplicate coords in payload → 400", status5 == 400, str(body5))

    # Missing ships array
    status6, body6 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player2_id})
    check("missing ships → 400",            status6 == 400, str(body6))

    # Fake player placing ships
    status7, body7 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": 999999, "ships": ships_p2})
    check("fake playerId on place → 404",   status7 == 404, str(body7))

    # Now let p2 place valid ships — game should transition to 'active'
    status8, body8 = api("post", f"/api/games/{game_id}/place",
                         {"playerId": player2_id, "ships": ships_p2})
    check("p2 places ships → 200",          status8 == 200, str(body8))

    # Verify game is now active
    _, game = api("get", f"/api/games/{game_id}")
    check("game transitions to 'active'",   game.get("status") == "active", str(game))


# ---------------------------------------------------------------------------
def test_fire_shot(game_id: int, player1_id: int, player2_id: int):
    section("POST /api/games/{id}/fire — fireShot()")

    # Determine whose turn it is first
    _, game = api("get", f"/api/games/{game_id}")
    turn_idx = game.get("current_turn_index", 0)

    # If player 1 is turn_order 0 they go first, else player 2
    # We'll fire p1 first regardless and check enforcement below

    # Fire at a miss cell (p2's ships are at row 9 cols 7-9; fire at 5,5)
    status, body = api("post", f"/api/games/{game_id}/fire",
                       {"playerId": player1_id, "row": 5, "col": 5})
    check("p1 fires (miss) → 200",          status == 200, str(body))
    check("result is 'miss'",               body.get("result") == "miss", str(body))
    check("next_player_id is set",          "next_player_id" in body, str(body))
    check("game_status is 'active'",        body.get("game_status") == "active", str(body))

    # It's now p2's turn — p1 firing again should be rejected
    status2, body2 = api("post", f"/api/games/{game_id}/fire",
                         {"playerId": player1_id, "row": 5, "col": 6})
    check("out-of-turn fire → 400",         status2 == 400, str(body2))

    # p2 fires a hit on p1's ship at (0,0)
    status3, body3 = api("post", f"/api/games/{game_id}/fire",
                         {"playerId": player2_id, "row": 0, "col": 0})
    check("p2 fires → 200",                 status3 == 200, str(body3))
    check("result is 'hit'",                body3.get("result") == "hit", str(body3))

    # Missing player_id
    status4, body4 = api("post", f"/api/games/{game_id}/fire",
                         {"row": 0, "col": 1})
    check("fire without player_id → 400",   status4 == 400, str(body4))

    # Fake player firing
    status5, body5 = api("post", f"/api/games/{game_id}/fire",
                         {"playerId": 999999, "row": 1, "col": 1})
    check("fake playerId fire → 404",       status5 == 404, str(body5))

    # Fire on non-existent game
    status6, body6 = api("post", "/api/games/999999/fire",
                         {"playerId": player1_id, "row": 0, "col": 0})
    check("fire on bad game_id → 404",      status6 == 404, str(body6))


# ---------------------------------------------------------------------------
def test_get_moves(game_id: int):
    section("GET /api/games/{id}/moves — getGameMoves()")

    status, body = api("get", f"/api/games/{game_id}/moves")
    check("get moves returns 200",          status == 200, str(body))
    check("returns a list",                 isinstance(body, list), str(body))

    if body:
        move = body[0]
        check("move has player_id",         "player_id"  in move, str(move))
        check("move has row",               "row"        in move, str(move))
        check("move has col",               "col"        in move, str(move))
        check("move has result",            "result"     in move, str(move))
        check("move has created_at",        "created_at" in move, str(move))
    else:
        print(f"{INFO}  No moves found — timestamp fields untestable (fire first)")

    # Non-existent game
    status2, body2 = api("get", "/api/games/999999/moves")
    check("moves for bad game → 200 empty or 404",
          status2 in (200, 404), str(body2))


# ---------------------------------------------------------------------------
def test_test_endpoints(game_id: int, player1_id: int, player2_id: int):
    section("Test-mode endpoints")

    # --- testResetGame ---
    status, body = api("post", f"/api/test/games/{game_id}/restart",
                       headers=TEST_HEADER)
    check("test restart → 200",             status == 200, str(body))

    # Verify game is back to 'waiting'
    _, game = api("get", f"/api/games/{game_id}")
    check("game reverts to 'waiting'",      game.get("status") == "waiting", str(game))

    # --- testPlaceShips ---
    ships_p1 = [{"row": 0, "col": i} for i in range(5)]
    ships_p2 = [{"row": 9, "col": i} for i in range(5)]

    status2, body2 = api("post", f"/api/test/games/{game_id}/ships",
                         {"playerId": player1_id, "ships": ships_p1},
                         headers=TEST_HEADER)
    check("test place ships p1 → 200",      status2 == 200, str(body2))

    status3, body3 = api("post", f"/api/test/games/{game_id}/ships",
                         {"playerId": player2_id, "ships": ships_p2},
                         headers=TEST_HEADER)
    check("test place ships p2 → 200",      status3 == 200, str(body3))

    # --- testGetBoard ---
    status4, body4 = api("get",
                         f"/api/test/games/{game_id}/board/{player1_id}",
                         headers=TEST_HEADER)
    check("test get board → 200",           status4 == 200, str(body4))
    check("board has ships list",           "ships" in body4, str(body4))
    check("board has moves list",           "moves" in body4, str(body4))
    check("board has sunk flag",            "sunk"  in body4, str(body4))
    check("correct number of ships placed", len(body4.get("ships", [])) == 5, str(body4))

    # --- Security: missing header ---
    status5, body5 = api("post", f"/api/test/games/{game_id}/restart",
                         headers={"Content-Type": "application/json"})
    check("test endpoint without header → 403", status5 == 403, str(body5))

    # Out-of-bounds via test/ships endpoint
    oob_ships = [{"row": 99, "col": 0}]
    status6, body6 = api("post", f"/api/test/games/{game_id}/ships",
                         {"playerId": player1_id, "ships": oob_ships},
                         headers=TEST_HEADER)
    check("test/ships out-of-bounds → 400", status6 == 400, str(body6))


# ---------------------------------------------------------------------------
def test_full_game_flow():
    """
    End-to-end game: two players, minimal ships, play until one wins.
    Validates game completion, stat updates, and winner_id.
    """
    section("Full game completion flow (end-to-end)")

    reset()

    # Create players
    _, alice = api("post", "/api/players", {"playerName": "Alice_E2E"})
    _, bob   = api("post", "/api/players", {"playerName": "Bob_E2E"})
    alice_id = alice["player_id"]
    bob_id   = bob["player_id"]

    # Create game with 1 ship each (easy to finish)
    _, game = api("post", "/api/games", {"gridSize": 5, "maxPlayers": 2})
    game_id = game["game_id"]

    # Join
    api("post", f"/api/games/{game_id}/join", {"playerId": alice_id})
    api("post", f"/api/games/{game_id}/join", {"playerId": bob_id})

    # Inject ships via test endpoint (1 cell each)
    api("post", f"/api/test/games/{game_id}/ships",
        {"playerId": alice_id, "ships": [{"row": 0, "col": 0}]},
        headers=TEST_HEADER)
    api("post", f"/api/test/games/{game_id}/ships",
        {"playerId": bob_id, "ships": [{"row": 4, "col": 4}]},
        headers=TEST_HEADER)

    _, game_state = api("get", f"/api/games/{game_id}")
    check("game is active after all ships placed",
          game_state.get("status") == "active", str(game_state))

    # Alice fires and misses
    _, r1 = api("post", f"/api/games/{game_id}/fire",
                {"playerId": alice_id, "row": 3, "col": 3})
    check("alice misses → result miss", r1.get("result") == "miss", str(r1))

    # Bob sinks alice's only ship
    _, r2 = api("post", f"/api/games/{game_id}/fire",
                {"playerId": bob_id, "row": 0, "col": 0})
    check("bob hits alice's ship",       r2.get("result") == "hit", str(r2))

    # Alice fires the winning shot
    _, r3 = api("post", f"/api/games/{game_id}/fire",
                {"playerId": alice_id, "row": 4, "col": 4})
    check("alice sinks bob's ship → hit", r3.get("result") == "hit", str(r3))
    check("game_status is 'finished'",    r3.get("game_status") == "finished", str(r3))
    check("winner_id is alice",           r3.get("winner_id") == alice_id, str(r3))

    # Cannot fire on a finished game
    _, r4 = api("post", f"/api/games/{game_id}/fire",
                {"playerId": alice_id, "row": 0, "col": 0})
    check("fire on finished game → 400", r4 == {} or r4.get("error") is not None, str(r4))

    # Stat updates
    _, alice_stats = api("get", f"/api/players/{alice_id}/stats")
    _, bob_stats   = api("get", f"/api/players/{bob_id}/stats")
    check("alice wins = 1",              alice_stats.get("wins") == 1, str(alice_stats))
    check("bob losses = 1",             bob_stats.get("losses") == 1, str(bob_stats))
    check("alice total_shots = 2",      alice_stats.get("total_shots") == 2, str(alice_stats))
    check("alice total_hits = 1",       alice_stats.get("total_hits") == 1, str(alice_stats))


# ---------------------------------------------------------------------------
def test_identity_reuse():
    """
    A player from a previous (finished) game can create/join a new game.
    Stats should accumulate across games.
    """
    section("Identity reuse across games")

    reset()

    _, alice = api("post", "/api/players", {"playerName": "Alice_Reuse"})
    _, bob   = api("post", "/api/players", {"playerName": "Bob_Reuse"})
    alice_id = alice["player_id"]
    bob_id   = bob["player_id"]

    def play_quick_game():
        _, g = api("post", "/api/games", {"gridSize": 5, "maxPlayers": 2})
        gid = g["game_id"]
        api("post", f"/api/games/{gid}/join", {"playerId": alice_id})
        api("post", f"/api/games/{gid}/join", {"playerId": bob_id})
        api("post", f"/api/test/games/{gid}/ships",
            {"playerId": alice_id, "ships": [{"row": 0, "col": 0}]},
            headers=TEST_HEADER)
        api("post", f"/api/test/games/{gid}/ships",
            {"playerId": bob_id, "ships": [{"row": 4, "col": 4}]},
            headers=TEST_HEADER)
        # Alice wins immediately
        api("post", f"/api/games/{gid}/fire",
            {"playerId": alice_id, "row": 4, "col": 4})
        # Bob fires to sink Alice (so we have a proper finished game second round)
        api("post", f"/api/games/{gid}/fire",
            {"playerId": bob_id, "row": 0, "col": 0})
        # Alice fires the final shot
        _, result = api("post", f"/api/games/{gid}/fire",
                        {"playerId": alice_id, "row": 4, "col": 4})
        return gid

    gid1 = play_quick_game()
    _, g1 = api("get", f"/api/games/{gid1}")
    check("game 1 finished",             g1.get("status") == "finished", str(g1))

    # Same players join a brand-new game
    _, g2 = api("post", "/api/games", {"gridSize": 5, "maxPlayers": 2})
    gid2 = g2["game_id"]

    s1, _ = api("post", f"/api/games/{gid2}/join", {"playerId": alice_id})
    check("alice joins second game → 200", s1 == 200)

    s2, _ = api("post", f"/api/games/{gid2}/join", {"playerId": bob_id})
    check("bob joins second game → 200",   s2 == 200)

    # Stats accumulate — alice should have at least 1 game_played after game 1
    _, stats = api("get", f"/api/players/{alice_id}/stats")
    check("alice games_played >= 1",       stats.get("games_played", 0) >= 1, str(stats))


# ---------------------------------------------------------------------------
def test_turn_enforcement():
    """
    Dedicated turn-enforcement checks beyond what test_fire_shot covers.
    Ensures wrong-player 400, and that after a valid fire the turn advances.
    """
    section("Turn enforcement (dedicated)")

    reset()

    _, p1 = api("post", "/api/players", {"playerName": "Turn_P1"})
    _, p2 = api("post", "/api/players", {"playerName": "Turn_P2"})
    p1_id, p2_id = p1["player_id"], p2["player_id"]

    _, g = api("post", "/api/games", {"gridSize": 5, "maxPlayers": 2})
    gid = g["game_id"]

    api("post", f"/api/games/{gid}/join", {"playerId": p1_id})
    api("post", f"/api/games/{gid}/join", {"playerId": p2_id})

    api("post", f"/api/test/games/{gid}/ships",
        {"playerId": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}]},
        headers=TEST_HEADER)
    api("post", f"/api/test/games/{gid}/ships",
        {"playerId": p2_id, "ships": [{"row": 4, "col": 4}, {"row": 4, "col": 3}]},
        headers=TEST_HEADER)

    # p2 tries to fire before p1
    _, r_bad = api("post", f"/api/games/{gid}/fire",
                   {"playerId": p2_id, "row": 0, "col": 0})
    check("p2 fires before their turn → error",
          r_bad.get("error") is not None, str(r_bad))

    # p1 fires (their turn, turn_order=0)
    status_ok, r_ok = api("post", f"/api/games/{gid}/fire",
                          {"playerId": p1_id, "row": 4, "col": 4})
    check("p1 fires on their turn → 200", status_ok == 200, str(r_ok))
    check("next_player_id is p2",
          r_ok.get("next_player_id") == p2_id, str(r_ok))

    # p1 tries to fire again immediately
    _, r_bad2 = api("post", f"/api/games/{gid}/fire",
                    {"playerId": p1_id, "row": 2, "col": 2})
    check("p1 fires twice in a row → error",
          r_bad2.get("error") is not None, str(r_bad2))


# ---------------------------------------------------------------------------
def test_duplicate_move_coordinates():
    """
    Fire the same cell twice — second shot should be treated as a miss
    (since is_hit=TRUE on the ship after first shot) or the API should
    reject it.  Either behaviour is acceptable; what matters is no crash.
    """
    section("Duplicate fire coordinates")

    reset()

    _, p1 = api("post", "/api/players", {"playerName": "Dup_P1"})
    _, p2 = api("post", "/api/players", {"playerName": "Dup_P2"})
    p1_id, p2_id = p1["player_id"], p2["player_id"]

    _, g = api("post", "/api/games", {"gridSize": 5, "maxPlayers": 2})
    gid = g["game_id"]

    api("post", f"/api/games/{gid}/join", {"playerId": p1_id})
    api("post", f"/api/games/{gid}/join", {"playerId": p2_id})
    api("post", f"/api/test/games/{gid}/ships",
        {"playerId": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 1, "col": 0}]},
        headers=TEST_HEADER)
    api("post", f"/api/test/games/{gid}/ships",
        {"playerId": p2_id, "ships": [{"row": 4, "col": 4}, {"row": 3, "col": 4}]},
        headers=TEST_HEADER)

    # p1 fires at (4,4) — hit
    _, r1 = api("post", f"/api/games/{gid}/fire",
                {"playerId": p1_id, "row": 4, "col": 4})
    check("first shot at (4,4) → 200", r1.get("result") in ("hit", "miss"), str(r1))

    # p2 fires, then p1 fires same cell again
    api("post", f"/api/games/{gid}/fire",
        {"playerId": p2_id, "row": 1, "col": 0})

    status2, r2 = api("post", f"/api/games/{gid}/fire",
                      {"playerId": p1_id, "row": 4, "col": 4})
    check("repeat coordinate doesn't crash (200 or 400)",
          status2 in (200, 400), str(r2))


# ---------------------------------------------------------------------------
def test_404_routing():
    section("Router — unknown endpoints")

    status, body = api("get", "/api/nonexistent")
    check("unknown route → 404",            status == 404, str(body))

    status2, body2 = api("delete", "/api/players")
    check("unsupported method → 404",       status2 == 404, str(body2))


# ===========================================================================
# MAIN
# ===========================================================================
def main():
    print(f"\nBattleship Regression Suite  →  {BASE_URL}\n")

    # --- Sanity check connectivity ---
    try:
        requests.get(BASE_URL, timeout=5)
    except requests.exceptions.ConnectionError:
        print(f"ERROR: Cannot reach {BASE_URL}. Is the server running?")
        sys.exit(1)

    # --- Global reset before suite ---
    reset()

    # --- Player tests ---
    alice_id = test_create_player()
    reset()

    # Recreate alice/bob for subsequent tests
    _, alice = api("post", "/api/players", {"playerName": "Alice"})
    _, bob   = api("post", "/api/players", {"playerName": "Bob"})
    alice_id = alice["player_id"]
    bob_id   = bob["player_id"]

    test_get_player_stats(alice_id)

    # --- Game lifecycle tests (shared game) ---
    game_id = test_create_game()
    reset()

    # Rebuild state for join/place/fire tests
    _, alice = api("post", "/api/players", {"playerName": "Alice"})
    _, bob   = api("post", "/api/players", {"playerName": "Bob"})
    alice_id = alice["player_id"]
    bob_id   = bob["player_id"]

    _, g = api("post", "/api/games", {"gridSize": 10, "maxPlayers": 2})
    game_id = g["game_id"]

    test_join_game(game_id, alice_id, bob_id)
    test_get_game(game_id)
    test_place_ships(game_id, alice_id, bob_id)

    # At this point game is active — run fire tests
    test_fire_shot(game_id, alice_id, bob_id)
    test_get_moves(game_id)

    # --- Test-mode endpoints (on a fresh game with alice+bob still in DB) ---
    _, g2 = api("post", "/api/games", {"gridSize": 10, "maxPlayers": 2})
    game2_id = g2["game_id"]
    api("post", f"/api/games/{game2_id}/join", {"playerId": alice_id})
    api("post", f"/api/games/{game2_id}/join", {"playerId": bob_id})
    test_test_endpoints(game2_id, alice_id, bob_id)

    # --- Advanced / new-feature coverage ---
    test_full_game_flow()
    test_identity_reuse()
    test_turn_enforcement()
    test_duplicate_move_coordinates()
    test_404_routing()

    # --- Summary ---
    total = results["passed"] + results["failed"]
    print(f"\n{'='*60}")
    print(f"  Results: {results['passed']}/{total} passed"
          f"  ({results['failed']} failed)")
    print(f"{'='*60}\n")

    sys.exit(0 if results["failed"] == 0 else 1)


if __name__ == "__main__":
    main()