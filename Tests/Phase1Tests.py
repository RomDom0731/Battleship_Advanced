#!/usr/bin/env python3
"""
Battleship API Test Suite
=========================
Built from the JSON test cases provided by all teams.

Usage:
    python test_suite.py                        # default: http://localhost:8080
    python test_suite.py http://my-server.com   # custom base URL

Requirements: Python 3.8+, requests
"""

import sys
import json
import requests

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
BASE_URL    = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "http://localhost:8080"
TEST_PASS   = "clemson-test-2026"
TEST_HDR    = {"X-Test-Password": TEST_PASS, "Content-Type": "application/json"}
STD_HDR     = {"Content-Type": "application/json"}

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
PASS = "\033[92mPASS\033[0m"
FAIL = "\033[91mFAIL\033[0m"

results = {"passed": 0, "failed": 0, "errors": []}


def check(test_id: str, label: str, condition: bool, detail: str = ""):
    if condition:
        print(f"  {PASS}  [{test_id}] {label}")
        results["passed"] += 1
    else:
        msg = f"  {FAIL}  [{test_id}] {label}"
        if detail:
            msg += f"\n         → {detail}"
        print(msg)
        results["failed"] += 1
        results["errors"].append(f"[{test_id}] {label}: {detail}")


def section(title: str):
    print(f"\n{'='*64}\n  {title}\n{'='*64}")


def api(method: str, path: str, body=None, headers=None):
    h = {**(headers or STD_HDR)}
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
    status, _ = api("post", "/api/reset")
    if status != 200:
        print(f"  [WARNING] Reset returned {status} — continuing anyway")


def matches(response: dict, expected: dict) -> bool:
    """
    Check that every key/value in `expected` appears in `response`.
    - If expected value is True/False (bool), only check that the key exists
      (some tests use {"error": true} to mean "any error key present").
    - Numeric player_id checks (expected_response_contains has player_id: 1)
      are treated as "must be a positive integer" since autograder resets
      between groups and IDs may not start at 1.
    """
    for key, val in expected.items():
        if key not in response:
            return False
        actual = response[key]
        # {"error": true} / {"status": true} → just check key is truthy
        if isinstance(val, bool):
            if val and not actual:
                return False
            continue
        # For player_id / game_id existence checks where expected value is an
        # integer, we only verify the key is present and is a positive int
        # (IDs will differ after resets).
        if key in ("player_id", "game_id") and isinstance(val, int):
            if not isinstance(actual, int) or actual <= 0:
                return False
            continue
        # Exact match for everything else
        if actual != val:
            return False
    return True


def build_headers(raw_headers: dict) -> dict:
    h = {"Content-Type": "application/json"}
    if "X-Test-Password" in raw_headers:
        h["X-Test-Password"] = raw_headers["X-Test-Password"]
    return h


# ---------------------------------------------------------------------------
# State tracked across tests within a group
# ---------------------------------------------------------------------------
class State:
    """Holds IDs created during a group so later tests in the group can use them."""
    def __init__(self):
        self.player_ids: dict[int, int] = {}   # creation_order → real_id
        self.game_ids:   dict[int, int] = {}   # creation_order → real_id
        self.player_count = 0
        self.game_count   = 0

    def store_player(self, real_id: int) -> int:
        self.player_count += 1
        self.player_ids[self.player_count] = real_id
        return self.player_count

    def store_game(self, real_id: int) -> int:
        self.game_count += 1
        self.game_ids[self.game_count] = real_id
        return self.game_count

    def resolve_path(self, path: str) -> str:
        """Replace :id / {id} / /1 style placeholders with actual IDs."""
        import re
        # /api/games/{id} or /api/games/:id → use most recently created game
        path = re.sub(r'/games/\{id\}', f'/games/{self.game_ids.get(self.game_count, 1)}', path)
        path = re.sub(r'/games/:id',    f'/games/{self.game_ids.get(self.game_count, 1)}', path)
        # /api/players/{id} or /api/players/:id
        path = re.sub(r'/players/\{id\}', f'/players/{self.player_ids.get(self.player_count, 1)}', path)
        path = re.sub(r'/players/:id',    f'/players/{self.player_ids.get(self.player_count, 1)}', path)
        # /board/:player_id
        path = re.sub(r'/board/:player_id', f'/board/{self.player_ids.get(1, 1)}', path)
        # Numeric literals — /games/1, /games/999, /players/1/stats, etc.
        # Replace /games/1 (small known ID) with the real game_id
        if self.game_count > 0:
            path = re.sub(r'/games/1\b', f'/games/{self.game_ids.get(1, 1)}', path)
        if self.player_count > 0:
            path = re.sub(r'/players/1\b', f'/players/{self.player_ids.get(1, 1)}', path)
            path = re.sub(r'/players/2\b', f'/players/{self.player_ids.get(2, 2)}', path)
        return path


# ---------------------------------------------------------------------------
# All JSON test cases (from the document, in order)
# ---------------------------------------------------------------------------
JSON_TESTS = [
    # ---- Team0x0A ----
    {
        "name": "Create game",
        "test_id": "T0001",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 8, "max_players": 2},  # creator_id filled at runtime
        "expected_status": 201,
        "expected_response_contains": {"status": "waiting_setup"},
        "group": "gameplay",
        "needs_player": True,
    },
    {
        "name": "Join game successfully",
        "test_id": "T0002",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},   # filled at runtime (player 2)
        "expected_status": 200,
        "expected_response_contains": {"status": "joined"},
        "group": "gameplay",
    },
    {
        "name": "Fire out of turn",
        "test_id": "T0003",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 0, "col": 0},   # player 1 fires when it's player 2's turn (after place)
        "expected_status": 403,
        "expected_response_contains": {"error": "forbidden"},
        "group": "gameplay",
        "fire_wrong_player": True,
    },
    {
        "name": "Duplicate fire attempt",
        "test_id": "T0004",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 3, "col": 4},
        "expected_status": 409,
        "expected_response_contains": {"error": "conflict"},
        "group": "gameplay",
        "fire_duplicate": True,
    },
    {
        "name": "Invalid game ID",
        "test_id": "T0005",
        "method": "GET", "endpoint": "/api/games/999",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone",
    },
    # ---- Team0x03 ----
    {
        "name": "Create player with valid username",
        "test_id": "T0006",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "testplayer_T0006"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Reject invalid username with special characters",
        "test_id": "T0007",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "bad!!name"},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
    },
    {
        "name": "Create game with valid parameters",
        "test_id": "T0008",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 10, "max_players": 2},
        "expected_status": 201,
        "expected_response_contains": {"status": "waiting_setup"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Reject game creation with grid_size below minimum",
        "test_id": "T0009",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 3, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Health endpoint returns ok status",
        "test_id": "T0015",
        "method": "GET", "endpoint": "/api/health",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"status": "ok"},
        "group": "standalone",
    },
    {
        "name": "Force username — reject empty string",
        "test_id": "T0016",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": ""},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "standalone",
    },
    {
        "name": "Player joins same game twice",
        "test_id": "T0018",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "double_join",
    },
    # ---- Team0x02 ----
    {
        "name": "Create player with valid username",
        "test_id": "T0021",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "mirpatel_T0021"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Reject duplicate username on player creation",
        "test_id": "T0022",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "mirpatel_T0022"},
        "expected_status": 409,
        "expected_response_contains": {"error": "conflict"},
        "group": "dup_username",
    },
    {
        "name": "Reject player creation with missing username",
        "test_id": "T0023",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
    },
    {
        "name": "Reject invalid username with special characters",
        "test_id": "T0024",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "dan!!!"},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
    },
    {
        "name": "Get stats for newly created player",
        "test_id": "T0025",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"games_played": 0, "wins": 0, "losses": 0, "total_shots": 0, "total_hits": 0},
        "group": "fresh_player_stats",
    },
    {
        "name": "Get stats for nonexistent player returns 404",
        "test_id": "T0026",
        "method": "GET", "endpoint": "/api/players/999/stats",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone",
    },
    {
        "name": "Create game with valid parameters",
        "test_id": "T0027",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 8, "max_players": 2},
        "expected_status": 201,
        "expected_response_contains": {"status": "waiting_setup"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Create game with minimum valid grid size",
        "test_id": "T0028",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 5, "max_players": 2},
        "expected_status": 201,
        "expected_response_contains": {"status": "waiting_setup"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Reject game creation with grid size too large",
        "test_id": "T0029",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 20, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Reject game creation with grid size too small",
        "test_id": "T0030",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 3, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Get game state returns correct fields",
        "test_id": "T0031",
        "method": "GET", "endpoint": "/api/games/1",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"game_id": 1, "status": "waiting_setup"},
        "group": "get_new_game",
    },
    {
        "name": "Get nonexistent game returns 404",
        "test_id": "T0032",
        "method": "GET", "endpoint": "/api/games/999",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone",
    },
    {
        "name": "Player 2 joins an existing waiting_setup game",
        "test_id": "T0033",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 200,
        "expected_response_contains": {"status": "joined"},
        "group": "join_flow",
    },
    {
        "name": "Join nonexistent game returns 404",
        "test_id": "T0034",
        "method": "POST", "endpoint": "/api/games/999/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone_with_player",
    },
    {
        "name": "Reject duplicate join in same game",
        "test_id": "T0035",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": "conflict"},
        "group": "double_join",
    },
    {
        "name": "Place ships with valid coordinates",
        "test_id": "T0036",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 1, "col": 1}, {"row": 2, "col": 2}]},
        "expected_status": 200,
        "expected_response_contains": {"status": "placed"},
        "group": "place_flow",
    },
    {
        "name": "Reject ship placement with out-of-bounds coordinates",
        "test_id": "T0037",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 99, "col": 0}, {"row": 1, "col": 1}, {"row": 2, "col": 2}]},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "place_validate",
    },
    {
        "name": "Reject ship placement with overlapping coordinates",
        "test_id": "T0038",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 0}, {"row": 2, "col": 2}]},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "place_validate",
    },
    {
        "name": "Reject second ship placement by same player",
        "test_id": "T0039",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 3, "col": 3}, {"row": 4, "col": 4}, {"row": 5, "col": 5}]},
        "expected_status": 409,
        "expected_response_contains": {"error": "conflict"},
        "group": "place_twice",
    },
    {
        "name": "Fire response includes next_player_id and game_status",
        "test_id": "T0047",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 2, "col": 2},
        "expected_status": 200,
        "expected_response_contains": {"game_status": "playing"},
        "group": "fire_active",
    },
    {
        "name": "Get move history for game",
        "test_id": "T0046",
        "method": "GET", "endpoint": "/api/games/1/moves",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {},
        "group": "moves_after_fire",
    },
    {
        "name": "Test mode restart resets game to waiting_setup",
        "test_id": "T0049",
        "method": "POST", "endpoint": "/api/test/games/1/restart",
        "headers": {"X-Test-Password": TEST_PASS},
        "body": None,
        "expected_status": 200,
        "expected_response_contains": {"status": "reset"},
        "group": "test_restart",
    },
    {
        "name": "Test mode restart blocked without password",
        "test_id": "T0050",
        "method": "POST", "endpoint": "/api/test/games/1/restart",
        "headers": {},
        "body": None,
        "expected_status": 403,
        "expected_response_contains": {"error": "forbidden"},
        "group": "test_restart",
    },
    # ---- Team0x0C ----
    {
        "name": "Error Case — test mode without security token",
        "test_id": "T0052",
        "method": "POST", "endpoint": "/api/test/games/1/ships",
        "headers": {},
        "body": {"player_id": 1, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]},
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "test_restart",
    },
    {
        "name": "Create game with invalid grid size (too large)",
        "test_id": "T0053",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"grid_size": 16, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
    },
    {
        "name": "Join game with non-existent player ID",
        "test_id": "T0055",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": 999999},
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "join_nonexistent_player",
    },
    # ---- Team0x0F ----
    {
        "name": "Reject Full Game Joining",
        "test_id": "T0057",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},    # 3rd player into a max-2 game
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "full_game",
    },
    {
        "name": "Reveal Board Unauthorized Access",
        "test_id": "T0059",
        "method": "GET", "endpoint": "/api/test/games/1/board/1",
        "headers": {}, "body": None,
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "test_restart",
    },
    {
        "name": "Statistics Integrity for New Player",
        "test_id": "T0060",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"wins": 0, "losses": 0, "accuracy": 0.0, "games_played": 0},
        "group": "fresh_player_stats",
    },
    {
        "name": "Reject game creation when required fields are missing",
        "test_id": "T0061",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Reject join request with non-existent player ID",
        "test_id": "T0063",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": 99999},
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "join_nonexistent_player",
    },
    {
        "name": "Reject firing at a coordinate that has already been targeted",
        "test_id": "T0064",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 0, "col": 0},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "fire_duplicate_dup",
    },
    {
        "name": "Reject player join request after game has already started",
        "test_id": "T0065",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "join_after_start",
    },
    # ---- Shaun Whitt / Jack Stivers ----
    {
        "name": "Reset system successfully",
        "test_id": "T0066",
        "method": "POST", "endpoint": "/api/reset",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"status": "reset"},
        "group": "standalone",
    },
    {
        "name": "Create first player successfully",
        "test_id": "T0067",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "player_test_T0067"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Create second player successfully",
        "test_id": "T0068",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "player_test2_T0068"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Join non-existent game",
        "test_id": "T0069",
        "method": "POST", "endpoint": "/api/games/999/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone_with_player",
    },
    {
        "name": "Create game successfully",
        "test_id": "T0070",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 8, "max_players": 2},
        "expected_status": 201,
        "expected_response_contains": {"status": "waiting_setup"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Fire before game reaches playing state",
        "test_id": "T0071",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 0, "col": 0},
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "fire_before_play",
    },
    # ---- Team0x04 ----
    {
        "name": "Valid 3-ship placement returns 200",
        "test_id": "T0077",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 1, "col": 0}, {"row": 2, "col": 0}]},
        "expected_status": 200,
        "expected_response_contains": {"status": "placed"},
        "group": "place_flow",
    },
    {
        "name": "Firing out of turn returns 403",
        "test_id": "T0078",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 0, "col": 0},
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "fire_wrong_turn",
    },
    {
        "name": "Joining a game already at max_players returns 409",
        "test_id": "T0081",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "full_game",
    },
    # ---- 0x05 ----
    {
        "name": "Create player with valid input",
        "test_id": "T0082",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "testuser_T0082"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Get stats for player with no activity",
        "test_id": "T0085",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"total_shots": 0},
        "group": "fresh_player_stats",
    },
    # ---- TeamBattleship ----
    {
        "name": "Create player with duplicate username",
        "test_id": "T0087",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "dupuser_T0087"},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "dup_username",
    },
    {
        "name": "Join game that is already full",
        "test_id": "T0088",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "full_game",
    },
    {
        "name": "Place ships with invalid number of cells",
        "test_id": "T0089",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}]},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "place_validate",
    },
    {
        "name": "Get stats for player with no games played",
        "test_id": "T0091",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"wins": 0, "losses": 0},
        "group": "fresh_player_stats",
    },
    # ---- Team 0x11 ----
    {
        "name": "Create player with valid username",
        "test_id": "T0092",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "warrior1_T0092"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Reject duplicate username",
        "test_id": "T0093",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "dup_T0093"},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "dup_username",
    },
    {
        "name": "Get stats for new player",
        "test_id": "T0094",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"games_played": 0, "wins": 0, "losses": 0, "total_shots": 0, "total_hits": 0, "accuracy": 0},
        "group": "fresh_player_stats",
    },
    {
        "name": "Reject game creation with grid too small",
        "test_id": "T0096",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 4, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Join nonexistent game",
        "test_id": "T0097",
        "method": "POST", "endpoint": "/api/games/9999/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "standalone_with_player",
    },
    {
        "name": "Join a game successfully before it starts",
        "test_id": "T0098",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 200,
        "expected_response_contains": {"status": "joined"},
        "group": "join_flow",
    },
    {
        "name": "Reject duplicate ship placement in one request",
        "test_id": "T0099",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 0}, {"row": 1, "col": 0}]},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "place_validate",
    },
    {
        "name": "Reject out of bounds fire",
        "test_id": "T0100",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": 8, "col": 8},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "fire_oob",
    },
    {
        "name": "Reject test restart without password",
        "test_id": "T0101",
        "method": "POST", "endpoint": "/api/test/games/1/restart",
        "headers": {}, "body": None,
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "test_restart",
    },
    # ---- Willing To Relocate / Team0x08 ----
    {
        "name": "Reject stats requests for non-existing player",
        "test_id": "T0102",
        "method": "GET", "endpoint": "/api/players/999999/stats",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": "not_found"},
        "group": "standalone",
    },
    {
        "name": "Stats reflect zero activity for a player who never fired",
        "test_id": "T0107",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"games_played": 0, "wins": 0, "losses": 0, "total_shots": 0, "total_hits": 0, "accuracy": 0.0},
        "group": "fresh_player_stats",
    },
    # ---- Team0x0A (continued) ----
    {
        "name": "Create new player successfully",
        "test_id": "T0112",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "Gabbie_Test_QA"},
        "expected_status": 201,
        "expected_response_contains": {"username": "Gabbie_Test_QA"},
        "group": "standalone",
    },
    {
        "name": "Prevent firing at out-of-bounds coordinates",
        "test_id": "T0114",
        "method": "POST", "endpoint": "/api/games/1/fire",
        "headers": {},
        "body": {"player_id": None, "row": -1, "col": 5},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "fire_oob",
    },
    {
        "name": "Calculate accuracy for new player with zero shots",
        "test_id": "T0115",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"total_shots": 0, "accuracy": 0.0},
        "group": "fresh_player_stats",
    },
    # ---- Team0x0D ----
    {
        "name": "GET stats for a new player - all zeros",
        "test_id": "T0122",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"games_played": 0, "wins": 0, "losses": 0, "total_shots": 0, "total_hits": 0, "accuracy": 0.0},
        "group": "fresh_player_stats",
    },
    {
        "name": "Place ships with out-of-bounds coordinates",
        "test_id": "T0123",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 99, "col": 99}, {"row": 1, "col": 0}, {"row": 2, "col": 0}]},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "place_validate",
    },
    {
        "name": "Join a game that does not exist",
        "test_id": "T0125",
        "method": "POST", "endpoint": "/api/games/99999/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "standalone_with_player",
    },
    # ---- The Software Shipmen ----
    {
        "name": "Create player with valid username",
        "test_id": "T0127",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "admiral_taylor_T0127"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Create player with duplicate username",
        "test_id": "T0128",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "dup_shipmen_T0128"},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "dup_username",
    },
    {
        "name": "Create player with missing username field",
        "test_id": "T0129",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
    },
    {
        "name": "Create game with grid_size below minimum",
        "test_id": "T0131",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 3, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
    {
        "name": "Get game state for non-existent game",
        "test_id": "T0133",
        "method": "GET", "endpoint": "/api/games/999999",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "standalone",
    },
    {
        "name": "Join game with valid player",
        "test_id": "T0134",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 200,
        "expected_response_contains": {"game_id": 1},
        "group": "join_flow",
    },
    {
        "name": "Join game with a player already in the game",
        "test_id": "T0135",
        "method": "POST", "endpoint": "/api/games/1/join",
        "headers": {},
        "body": {"player_id": None},
        "expected_status": 409,
        "expected_response_contains": {"error": True},
        "group": "double_join",
    },
    {
        "name": "Place ships with exactly 3 valid positions",
        "test_id": "T0136",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 2, "col": 4}, {"row": 5, "col": 5}]},
        "expected_status": 200,
        "expected_response_contains": {"status": "placed"},
        "group": "place_flow",
    },
    {
        "name": "Place ships with duplicate positions",
        "test_id": "T0137",
        "method": "POST", "endpoint": "/api/games/1/place",
        "headers": {},
        "body": {"player_id": None, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 0}, {"row": 5, "col": 5}]},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "place_validate",
    },
    {
        "name": "Get move history for a game",
        "test_id": "T0140",
        "method": "GET", "endpoint": "/api/games/1/moves",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"game_id": 1},
        "group": "moves_after_fire",
    },
    {
        "name": "Get player stats for existing player",
        "test_id": "T0141",
        "method": "GET", "endpoint": "/api/players/1/stats",
        "headers": {}, "body": None,
        "expected_status": 200,
        "expected_response_contains": {"games_played": 0},
        "group": "fresh_player_stats",
    },
    {
        "name": "Get player stats for non-existent player",
        "test_id": "T0142",
        "method": "GET", "endpoint": "/api/players/999999/stats",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "standalone",
    },
    {
        "name": "Get board via test-mode endpoint with valid password",
        "test_id": "T0143",
        "method": "GET", "endpoint": "/api/test/games/1/board/1",
        "headers": {"X-Test-Password": TEST_PASS},
        "body": None,
        "expected_status": 200,
        "expected_response_contains": {"game_id": 1},
        "group": "test_board",
    },
    {
        "name": "Access test-mode endpoint without password header",
        "test_id": "T0144",
        "method": "GET", "endpoint": "/api/test/games/1/board/1",
        "headers": {}, "body": None,
        "expected_status": 403,
        "expected_response_contains": {"error": True},
        "group": "test_restart",
    },
    {
        "name": "Reset game via test-mode endpoint",
        "test_id": "T0145",
        "method": "POST", "endpoint": "/api/test/games/1/restart",
        "headers": {"X-Test-Password": TEST_PASS},
        "body": {},
        "expected_status": 200,
        "expected_response_contains": {"status": "reset"},
        "group": "test_restart_authed",
    },
    # ---- Team0x0B ----
    {
        "name": "Reset server state",
        "test_id": "T0147",
        "method": "POST", "endpoint": "/api/reset",
        "headers": {}, "body": {},
        "expected_status": 200,
        "expected_response_contains": {"status": "reset"},
        "group": "standalone",
    },
    {
        "name": "Create player with valid username",
        "test_id": "T0148",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {"username": "qa_player_T0148"},
        "expected_status": 201,
        "expected_response_contains": {"player_id": 1},
        "group": "standalone",
    },
    {
        "name": "Create player with missing username",
        "test_id": "T0149",
        "method": "POST", "endpoint": "/api/players",
        "headers": {},
        "body": {},
        "expected_status": 400,
        "expected_response_contains": {"error": True},
        "group": "standalone",
    },
    {
        "name": "Get stats for nonexistent player",
        "test_id": "T0150",
        "method": "GET", "endpoint": "/api/players/9999/stats",
        "headers": {}, "body": None,
        "expected_status": 404,
        "expected_response_contains": {"error": True},
        "group": "standalone",
    },
    {
        "name": "Create game with grid size below minimum",
        "test_id": "T0151",
        "method": "POST", "endpoint": "/api/games",
        "headers": {},
        "body": {"creator_id": None, "grid_size": 4, "max_players": 2},
        "expected_status": 400,
        "expected_response_contains": {"error": "bad_request"},
        "group": "standalone",
        "needs_player": True,
    },
]


# ---------------------------------------------------------------------------
# Test group runners — each group sets up its own state and resolves IDs
# ---------------------------------------------------------------------------

def run_standalone_tests():
    """Tests that need only a reset, no shared state."""
    section("Standalone / stateless tests")
    reset()
    p1_id = None

    for t in JSON_TESTS:
        if t["group"] not in ("standalone",):
            continue
        test_id = t["test_id"]
        body = dict(t["body"]) if t["body"] else None

        # If this test needs a creator_id and we don't have one yet, make a player
        if t.get("needs_player") and p1_id is None:
            _, pr = api("post", "/api/players", {"username": f"creator_{test_id}"})
            p1_id = pr.get("player_id")

        if body and "creator_id" in body and body["creator_id"] is None:
            body["creator_id"] = p1_id

        hdrs = build_headers(t["headers"])
        status, resp = api(t["method"].lower(), t["endpoint"], body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(test_id, t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_standalone_with_player():
    section("Standalone — requires a player to exist for join paths")
    reset()
    _, pr = api("post", "/api/players", {"username": "solo_player"})
    p_id = pr.get("player_id")

    for t in JSON_TESTS:
        if t["group"] != "standalone_with_player":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and "player_id" in body and body["player_id"] is None:
            body["player_id"] = p_id
        hdrs = build_headers(t["headers"])
        status, resp = api(t["method"].lower(), t["endpoint"], body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fresh_player_stats():
    """All tests that expect a brand-new player's stats to be all zeros."""
    section("Fresh player stats — zero state checks")
    reset()
    _, pr = api("post", "/api/players", {"username": "fresh_stats_player"})
    p_id = pr.get("player_id")
    p_path = f"/api/players/{p_id}/stats"

    for t in JSON_TESTS:
        if t["group"] != "fresh_player_stats":
            continue
        hdrs = build_headers(t["headers"])
        status, resp = api("get", p_path, None, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_duplicate_username():
    """Tests that insert the same username twice and expect 409."""
    section("Duplicate username rejection")
    for t in JSON_TESTS:
        if t["group"] != "dup_username":
            continue
        reset()
        # First create the user
        uname = t["body"]["username"]
        api("post", "/api/players", {"username": uname})
        # Now attempt the duplicate
        hdrs = build_headers(t["headers"])
        body = dict(t["body"])
        status, resp = api("post", "/api/players", body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_get_new_game():
    """Tests that GET a newly created game (waiting_setup) and check fields."""
    section("GET new game — field checks")
    reset()
    _, pr = api("post", "/api/players", {"username": "game_getter"})
    p_id = pr.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p_id, "grid_size": 8, "max_players": 2})
    g_id = gr.get("game_id")

    for t in JSON_TESTS:
        if t["group"] != "get_new_game":
            continue
        path = f"/api/games/{g_id}"
        hdrs = build_headers(t["headers"])
        status, resp = api("get", path, None, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_join_flow():
    """Each join_flow test gets its own fresh reset+players+game to avoid 409 on repeated joins."""
    section("Join flow — second player joins successfully")
    join_flow_tests = [t for t in JSON_TESTS if t["group"] == "join_flow"]
    for i, t in enumerate(join_flow_tests):
        reset()
        _, p1r = api("post", "/api/players", {"username": f"jh_{i}"})
        _, p2r = api("post", "/api/players", {"username": f"jg_{i}"})
        p1_id = p1r.get("player_id")
        p2_id = p2r.get("player_id")
        _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 3})
        g_id = gr.get("game_id")

        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p2_id
        path = f"/api/games/{g_id}/join"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        exp = dict(t["expected_response_contains"])
        if "game_id" in exp:
            exp["game_id"] = g_id
        ok = (status == t["expected_status"] and matches(resp, exp))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")

def run_double_join():
    """Player joins and then tries to join the same game again."""
    section("Double join rejection")
    reset()
    _, p1r = api("post", "/api/players", {"username": "dbl_host"})
    _, p2r = api("post", "/api/players", {"username": "dbl_guest"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
    g_id = gr.get("game_id")
    # p2 joins once
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})

    for t in JSON_TESTS:
        if t["group"] != "double_join":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p2_id
        path = f"/api/games/{g_id}/join"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_full_game():
    """Game is at capacity — third player tries to join."""
    section("Full game — extra join rejected")
    reset()
    _, p1r = api("post", "/api/players", {"username": "full_p1"})
    _, p2r = api("post", "/api/players", {"username": "full_p2"})
    _, p3r = api("post", "/api/players", {"username": "full_p3"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    p3_id = p3r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})   # fills to cap

    for t in JSON_TESTS:
        if t["group"] != "full_game":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p3_id
        path = f"/api/games/{g_id}/join"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_place_flow():
    """Valid ship placements — player in a game places exactly 3 ships."""
    section("Ship placement — valid placement accepted")
    reset()
    _, p1r = api("post", "/api/players", {"username": "place_p1"})
    p1_id = p1r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
    g_id = gr.get("game_id")

    for t in JSON_TESTS:
        if t["group"] != "place_flow":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/place"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")
        # Reset between each so each test gets a clean slate
        reset()
        _, p1r = api("post", "/api/players", {"username": "place_p1"})
        p1_id = p1r.get("player_id")
        _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
        g_id = gr.get("game_id")


def run_place_validate():
    """Bad placements — out of bounds, duplicates, wrong count."""
    section("Ship placement — validation rejects bad input")
    for t in JSON_TESTS:
        if t["group"] != "place_validate":
            continue
        reset()
        _, p1r = api("post", "/api/players", {"username": "pv_host"})
        _, p2r = api("post", "/api/players", {"username": "pv_guest"})
        p1_id = p1r.get("player_id")
        p2_id = p2r.get("player_id")
        _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
        g_id = gr.get("game_id")
        api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})

        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p2_id
        path = f"/api/games/{g_id}/place"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_place_twice():
    """Player tries to place ships a second time — should be 409."""
    section("Ship placement — second placement rejected")
    for t in JSON_TESTS:
        if t["group"] != "place_twice":
            continue
        reset()
        _, p1r = api("post", "/api/players", {"username": "pt_host"})
        p1_id = p1r.get("player_id")
        _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
        g_id = gr.get("game_id")
        # Place once successfully
        api("post", f"/api/games/{g_id}/place",
            {"player_id": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 1, "col": 1}, {"row": 2, "col": 2}]})
        # Second placement
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/place"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def _setup_active_game():
    """Helper: create two players, a game, both place ships. Returns (g_id, p1_id, p2_id)."""
    reset()
    _, p1r = api("post", "/api/players", {"username": "active_p1"})
    _, p2r = api("post", "/api/players", {"username": "active_p2"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 5, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})
    api("post", f"/api/games/{g_id}/place",
        {"player_id": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]})
    api("post", f"/api/games/{g_id}/place",
        {"player_id": p2_id, "ships": [{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]})
    return g_id, p1_id, p2_id


def run_fire_active():
    """Fire returns result + game_status on an active game."""
    section("Fire — active game response fields")
    g_id, p1_id, p2_id = _setup_active_game()
    for t in JSON_TESTS:
        if t["group"] != "fire_active":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/fire"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fire_wrong_turn():
    """Player 2 fires when it's player 1's turn."""
    section("Fire — wrong turn rejected (403)")
    g_id, p1_id, p2_id = _setup_active_game()
    for t in JSON_TESTS:
        if t["group"] != "fire_wrong_turn":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p2_id   # it's p1's turn
        path = f"/api/games/{g_id}/fire"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fire_oob():
    """Fire coordinates outside grid bounds — expect 400."""
    section("Fire — out-of-bounds coordinates rejected")
    g_id, p1_id, p2_id = _setup_active_game()
    for t in JSON_TESTS:
        if t["group"] != "fire_oob":
            continue
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/fire"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fire_duplicate():
    """Same cell fired twice → 409."""
    section("Fire — duplicate cell rejected (409)")
    for t in JSON_TESTS:
        if t["group"] not in ("fire_duplicate_dup",):
            continue
        g_id, p1_id, p2_id = _setup_active_game()
        # Fire once at (0,0) — p2's board doesn't have a ship there, so it's a miss
        api("post", f"/api/games/{g_id}/fire", {"player_id": p1_id, "row": 0, "col": 0})
        # p2 fires so turn comes back to p1
        api("post", f"/api/games/{g_id}/fire", {"player_id": p2_id, "row": 1, "col": 1})
        # p1 fires same cell again
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/fire"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fire_before_play():
    """Fire while game is still in waiting_setup → 403."""
    section("Fire — game not yet active")
    for t in JSON_TESTS:
        if t["group"] != "fire_before_play":
            continue
        reset()
        _, p1r = api("post", "/api/players", {"username": "fbp_p1"})
        p1_id = p1r.get("player_id")
        _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 5, "max_players": 2})
        g_id = gr.get("game_id")
        # Do NOT place ships — game stays in waiting_setup
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p1_id
        path = f"/api/games/{g_id}/fire"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_moves_after_fire():
    """GET /moves after at least one shot has been fired."""
    section("Move history — after firing")
    g_id, p1_id, p2_id = _setup_active_game()
    api("post", f"/api/games/{g_id}/fire", {"player_id": p1_id, "row": 3, "col": 3})

    for t in JSON_TESTS:
        if t["group"] != "moves_after_fire":
            continue
        path = f"/api/games/{g_id}/moves"
        exp = dict(t["expected_response_contains"])
        if "game_id" in exp:
            exp["game_id"] = g_id
        hdrs = build_headers(t["headers"])
        status, resp = api("get", path, None, hdrs)
        ok = (status == t["expected_status"] and matches(resp, exp))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_gameplay_sequence():
    """
    Drives the T0001–T0004 group which needs sequential state:
    create game → join → place ships → fire out-of-turn → duplicate fire.
    """
    section("Gameplay sequence (T0001–T0004)")
    reset()
    _, p1r = api("post", "/api/players", {"username": "gp_seq_p1"})
    _, p2r = api("post", "/api/players", {"username": "gp_seq_p2"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")

    # T0001 — Create game (creator = p1)
    t = next(x for x in JSON_TESTS if x["test_id"] == "T0001")
    body = {"creator_id": p1_id, "grid_size": 8, "max_players": 2}
    status, resp = api("post", "/api/games", body, build_headers(t["headers"]))
    g_id = resp.get("game_id")
    ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
    check(t["test_id"], t["name"], ok, f"status={status}, body={resp}")

    # T0002 — p2 joins
    t = next(x for x in JSON_TESTS if x["test_id"] == "T0002")
    status, resp = api("post", f"/api/games/{g_id}/join", {"player_id": p2_id}, build_headers(t["headers"]))
    ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
    check(t["test_id"], t["name"], ok, f"status={status}, body={resp}")

    # Both place ships so game enters playing state
    api("post", f"/api/games/{g_id}/place",
        {"player_id": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]})
    api("post", f"/api/games/{g_id}/place",
        {"player_id": p2_id, "ships": [{"row": 7, "col": 7}, {"row": 7, "col": 6}, {"row": 7, "col": 5}]})

    # T0003 — Fire out of turn: it's p1's turn, send p2 firing instead
    t = next(x for x in JSON_TESTS if x["test_id"] == "T0003")
    status, resp = api("post", f"/api/games/{g_id}/fire",
                       {"player_id": p2_id, "row": 0, "col": 0}, build_headers(t["headers"]))
    ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
    check(t["test_id"], t["name"], ok, f"status={status}, body={resp}")

    # p1 fires a valid shot to advance the turn to p2
    api("post", f"/api/games/{g_id}/fire", {"player_id": p1_id, "row": 3, "col": 4})
    # p2 fires (3,4) once — hit or miss
    api("post", f"/api/games/{g_id}/fire", {"player_id": p2_id, "row": 3, "col": 4})
    # Turn back to p1 — advance turn again so p2 can fire a dup
    api("post", f"/api/games/{g_id}/fire", {"player_id": p1_id, "row": 1, "col": 0})

    # T0004 — Duplicate fire: p2 fires same cell again
    t = next(x for x in JSON_TESTS if x["test_id"] == "T0004")
    status, resp = api("post", f"/api/games/{g_id}/fire",
                       {"player_id": p2_id, "row": 3, "col": 4}, build_headers(t["headers"]))
    ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
    check(t["test_id"], t["name"], ok, f"status={status}, body={resp}")


def run_join_nonexistent_player():
    """Joining with a player_id that does not exist → 404."""
    section("Join — nonexistent player rejected")
    reset()
    _, p1r = api("post", "/api/players", {"username": "jnep_host"})
    p1_id = p1r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 8, "max_players": 2})
    g_id = gr.get("game_id")

    for t in JSON_TESTS:
        if t["group"] != "join_nonexistent_player":
            continue
        path = f"/api/games/{g_id}/join"
        body = dict(t["body"]) if t["body"] else None
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_join_after_start():
    """Joining a game that is already in playing state → 409."""
    section("Join — game already started")
    for t in JSON_TESTS:
        if t["group"] != "join_after_start":
            continue
        g_id, p1_id, p2_id = _setup_active_game()
        _, p3r = api("post", "/api/players", {"username": "late_joiner"})
        p3_id = p3r.get("player_id")
        body = dict(t["body"]) if t["body"] else None
        if body and body.get("player_id") is None:
            body["player_id"] = p3_id
        path = f"/api/games/{g_id}/join"
        hdrs = build_headers(t["headers"])
        status, resp = api("post", path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_test_restart():
    """Test-mode endpoints: restart blocked without password, allowed with it."""
    section("Test-mode — restart and auth checks")
    reset()
    _, p1r = api("post", "/api/players", {"username": "tr_p1"})
    _, p2r = api("post", "/api/players", {"username": "tr_p2"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 5, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})

    for t in JSON_TESTS:
        if t["group"] != "test_restart":
            continue
        # Substitute real IDs into path
        path = t["endpoint"]
        path = path.replace("/test/games/1/", f"/test/games/{g_id}/")
        path = path.replace("/board/1", f"/board/{p1_id}")
        hdrs = build_headers(t["headers"])
        body = dict(t["body"]) if t["body"] else None
        status, resp = api(t["method"].lower(), path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_test_restart_authed():
    """Test-mode restart with correct password."""
    section("Test-mode — authenticated restart")
    reset()
    _, p1r = api("post", "/api/players", {"username": "tra_p1"})
    _, p2r = api("post", "/api/players", {"username": "tra_p2"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 5, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})

    for t in JSON_TESTS:
        if t["group"] != "test_restart_authed":
            continue
        path = t["endpoint"].replace("/test/games/1/", f"/test/games/{g_id}/")
        hdrs = build_headers(t["headers"])
        body = dict(t["body"]) if t["body"] else None
        status, resp = api(t["method"].lower(), path, body, hdrs)
        ok = (status == t["expected_status"] and matches(resp, t["expected_response_contains"]))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_test_board():
    """Test-mode board inspection with valid password."""
    section("Test-mode — board inspection")
    reset()
    _, p1r = api("post", "/api/players", {"username": "tb_p1"})
    _, p2r = api("post", "/api/players", {"username": "tb_p2"})
    p1_id = p1r.get("player_id")
    p2_id = p2r.get("player_id")
    _, gr = api("post", "/api/games", {"creator_id": p1_id, "grid_size": 5, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": p2_id})
    # Inject ships via test endpoint
    api("post", f"/api/test/games/{g_id}/ships",
        {"player_id": p1_id, "ships": [{"row": 0, "col": 0}, {"row": 1, "col": 0}, {"row": 2, "col": 0}]},
        TEST_HDR)

    for t in JSON_TESTS:
        if t["group"] != "test_board":
            continue
        path = f"/api/test/games/{g_id}/board/{p1_id}"
        exp = dict(t["expected_response_contains"])
        if "game_id" in exp:
            exp["game_id"] = g_id
        hdrs = build_headers(t["headers"])
        status, resp = api("get", path, None, hdrs)
        ok = (status == t["expected_status"] and matches(resp, exp))
        check(t["test_id"], t["name"], ok,
              f"status={status} (expected {t['expected_status']}), body={resp}")


def run_fire_gameplay_T0003_T0004():
    """Covers the original T0003/T0004 group tests within gameplay_sequence,
    but also the standalone fire-wrong-player and duplicate from the gameplay group."""
    # These are already handled inside run_gameplay_sequence
    pass


# ---------------------------------------------------------------------------
# End-to-end full game flow (win / stat accumulation)
# ---------------------------------------------------------------------------
def run_e2e_full_game():
    section("End-to-end: full game → winner → stats")
    reset()
    _, ar = api("post", "/api/players", {"username": "e2e_alice"})
    _, br = api("post", "/api/players", {"username": "e2e_bob"})
    a_id = ar.get("player_id")
    b_id = br.get("player_id")

    _, gr = api("post", "/api/games", {"creator_id": a_id, "grid_size": 5, "max_players": 2})
    g_id = gr.get("game_id")
    api("post", f"/api/games/{g_id}/join", {"player_id": b_id})

    # Inject ships: alice (0,0)(0,1)(0,2), bob (4,2)(4,3)(4,4)
    api("post", f"/api/test/games/{g_id}/ships",
        {"player_id": a_id, "ships": [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]}, TEST_HDR)
    api("post", f"/api/test/games/{g_id}/ships",
        {"player_id": b_id, "ships": [{"row": 4, "col": 2}, {"row": 4, "col": 3}, {"row": 4, "col": 4}]}, TEST_HDR)

    _, gs = api("get", f"/api/games/{g_id}")
    check("E2E-01", "Game is playing after all ships placed",
          gs.get("status") == "playing", str(gs))

    # Alice hits all 3 of bob's ships, bob misses in between
    _, r1 = api("post", f"/api/games/{g_id}/fire", {"player_id": a_id, "row": 4, "col": 2})
    check("E2E-02", "Alice shot 1 → hit", r1.get("result") == "hit", str(r1))
    check("E2E-03", "Game still playing", r1.get("game_status") == "playing", str(r1))

    _, r2 = api("post", f"/api/games/{g_id}/fire", {"player_id": b_id, "row": 3, "col": 3})
    check("E2E-04", "Bob misses", r2.get("result") == "miss", str(r2))

    _, r3 = api("post", f"/api/games/{g_id}/fire", {"player_id": a_id, "row": 4, "col": 3})
    check("E2E-05", "Alice shot 2 → hit", r3.get("result") == "hit", str(r3))

    _, r4 = api("post", f"/api/games/{g_id}/fire", {"player_id": b_id, "row": 3, "col": 2})
    check("E2E-06", "Bob misses again", r4.get("result") == "miss", str(r4))

    _, r5 = api("post", f"/api/games/{g_id}/fire", {"player_id": a_id, "row": 4, "col": 4})
    check("E2E-07", "Alice sinks last bob ship → game finished",
          r5.get("game_status") == "finished", str(r5))
    check("E2E-08", "Winner is Alice", r5.get("winner_id") == a_id, str(r5))

    # Cannot fire on finished game
    status_done, r6 = api("post", f"/api/games/{g_id}/fire", {"player_id": b_id, "row": 0, "col": 0})
    check("E2E-09", "Fire on finished game rejected",
          status_done != 200, str(r6))

    # Stats
    _, as_ = api("get", f"/api/players/{a_id}/stats")
    _, bs_ = api("get", f"/api/players/{b_id}/stats")
    check("E2E-10", "Alice wins=1",         as_.get("wins") == 1,        str(as_))
    check("E2E-11", "Bob losses=1",         bs_.get("losses") == 1,      str(bs_))
    check("E2E-12", "Alice total_shots=3",  as_.get("total_shots") == 3, str(as_))
    check("E2E-13", "Alice total_hits=3",   as_.get("total_hits") == 3,  str(as_))
    check("E2E-14", "Bob total_shots=2",    bs_.get("total_shots") == 2, str(bs_))
    check("E2E-15", "Bob total_hits=0",     bs_.get("total_hits") == 0,  str(bs_))
    check("E2E-16", "Alice accuracy=1.0",   as_.get("accuracy") == 1.0,  str(as_))
    check("E2E-17", "Bob accuracy=0.0",     bs_.get("accuracy") == 0.0,  str(bs_))

    # Test restart preserves player stats
    api("post", f"/api/test/games/{g_id}/restart", None, TEST_HDR)
    _, as2 = api("get", f"/api/players/{a_id}/stats")
    check("E2E-18", "Alice stats survive game restart", as2.get("wins") == 1, str(as2))

    _, gs2 = api("get", f"/api/games/{g_id}")
    check("E2E-19", "Game reset to waiting_setup", gs2.get("status") == "waiting_setup", str(gs2))


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main():
    print(f"\nBattleship JSON Test Suite  →  {BASE_URL}\n")

    try:
        requests.get(BASE_URL, timeout=5)
    except requests.exceptions.ConnectionError:
        print(f"ERROR: Cannot reach {BASE_URL}. Is the server running?")
        sys.exit(1)

    reset()

    # Run all groups
    run_standalone_tests()
    run_standalone_with_player()
    run_fresh_player_stats()
    run_duplicate_username()
    run_get_new_game()
    run_join_flow()
    run_double_join()
    run_full_game()
    run_place_flow()
    run_place_validate()
    run_place_twice()
    run_fire_active()
    run_fire_wrong_turn()
    run_fire_oob()
    run_fire_duplicate()
    run_fire_before_play()
    run_moves_after_fire()
    run_gameplay_sequence()
    run_join_nonexistent_player()
    run_join_after_start()
    run_test_restart()
    run_test_restart_authed()
    run_test_board()
    run_e2e_full_game()

    total = results["passed"] + results["failed"]
    print(f"\n{'='*64}")
    print(f"  Results: {results['passed']}/{total} passed  ({results['failed']} failed)")
    print(f"{'='*64}\n")

    if results["errors"]:
        print("Failed tests:")
        for e in results["errors"]:
            print(f"  • {e}")
        print()

    sys.exit(0 if results["failed"] == 0 else 1)


if __name__ == "__main__":
    main()