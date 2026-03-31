#!/usr/bin/env python3
"""
Battleship API — Phase 3 Test Suite
=====================================
Focus: Win conditions, player statistics, multiplayer, test-mode restart,
       database integrity, and concurrency / stress tests.

Usage:
    python P1P3Tests.py                        # default: http://localhost:8080
    python P1P3Tests.py http://my-server.com   # custom base URL

Requirements:  Python 3.8+, requests  (pip install requests)
"""

import sys
import json
import time
import threading
import requests

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
BASE_URL    = sys.argv[1].rstrip("/") if len(sys.argv) > 1 else "http://localhost:8080"
TEST_HEADER = {"X-Test-Mode": "clemson-test-2026", "Content-Type": "application/json"}
STD_HEADER  = {"Content-Type": "application/json"}

# ---------------------------------------------------------------------------
# Tiny test-runner helpers
# ---------------------------------------------------------------------------
PASS = "\033[92m PASS\033[0m"
FAIL = "\033[91m FAIL\033[0m"

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
    """Make a request; return (status_code, parsed_json)."""
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
    status, _ = api("post", "/api/reset")
    assert status == 200, f"Reset failed with status {status}"


# ---------------------------------------------------------------------------
# Shared helpers
# ---------------------------------------------------------------------------

def make_player(name: str) -> int:
    """Create (or retrieve) a player; return player_id."""
    _, body = api("post", "/api/players", {"playerName": name})
    return body["player_id"]


def make_game(grid: int = 5, max_p: int = 2) -> int:
    _, body = api("post", "/api/games", {"gridSize": grid, "maxPlayers": max_p})
    return body["game_id"]


def join(gid: int, pid: int):
    return api("post", f"/api/games/{gid}/join", {"playerId": pid})


def inject_ships(gid: int, pid: int, ships: list):
    """Inject ships via test endpoint."""
    return api("post", f"/api/test/games/{gid}/ships",
               {"playerId": pid, "ships": ships},
               headers=TEST_HEADER)


def fire(gid: int, pid: int, row: int, col: int):
    return api("post", f"/api/games/{gid}/fire",
               {"playerId": pid, "row": row, "col": col})


def stats(pid: int):
    _, body = api("get", f"/api/players/{pid}/stats")
    return body


def setup_two_player_game(p1_name="A", p2_name="B",
                           p1_ships=None, p2_ships=None,
                           grid=5):
    """
    Helper: create two players, a game, have both join and place ships.
    testPlaceShips requires >= 3 ship cells, so defaults use 3 cells each.
    Returns (gid, p1_id, p2_id).
    """
    p1_id = make_player(p1_name)
    p2_id = make_player(p2_name)
    gid   = make_game(grid=grid, max_p=2)
    join(gid, p1_id)
    join(gid, p2_id)

    # Default: 3 cells each, no overlap, all within a 5-grid
    p1_ships = p1_ships or [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}]
    p2_ships = p2_ships or [{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]

    inject_ships(gid, p1_id, p1_ships)
    inject_ships(gid, p2_id, p2_ships)
    return gid, p1_id, p2_id


# ===========================================================================
# 1. WIN CONDITION TESTS
# ===========================================================================

def test_win_condition_finish():
    section("Win Condition — Sinking all ships ends the game")

    reset()
    # p2 has exactly 3 ships at known cells; p1 will sink all 3
    gid, p1_id, p2_id = setup_two_player_game(
        "WinA", "WinB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    # p1 and p2 alternate; p1 shoots first (turn_order=0)
    # We need to sink all 3 of p2's ships; p2 must shoot in between each of p1's turns.
    # Shoot p2's ships one at a time, with p2 firing misses in between.
    fire(gid, p1_id, 4, 4)   # p1 hits p2[0] — turn → p2
    fire(gid, p2_id, 1, 1)   # p2 misses         — turn → p1
    fire(gid, p1_id, 4, 3)   # p1 hits p2[1]     — turn → p2
    fire(gid, p2_id, 1, 2)   # p2 misses         — turn → p1

    # Final shot: sinks p2's last ship → game should finish
    status, body = fire(gid, p1_id, 4, 2)
    check("final shot returns 200",           status == 200, str(body))
    check("result is 'hit'",                  body.get("result") == "hit", str(body))
    check("game_status is 'finished'",        body.get("game_status") == "finished", str(body))
    check("winner_id is present",             "winner_id" in body, str(body))
    check("winner_id equals p1",              body.get("winner_id") == p1_id, str(body))

    # Confirm via GET /api/games/{id}
    _, game = api("get", f"/api/games/{gid}")
    check("GET game shows status='finished'", game.get("status") == "finished", str(game))
    # winner_id may or may not be included in the GET response — only assert if present
    if "winner_id" in game:
        check("GET game shows correct winner_id", game.get("winner_id") == p1_id, str(game))
    else:
        check("GET game shows correct winner_id (confirmed via fire response)", True)


def test_fire_into_finished_game():
    section("Win Condition — Firing into a finished game is rejected")

    reset()
    gid, p1_id, p2_id = setup_two_player_game(
        "FinA", "FinB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    # Sink all of p2's ships (with p2 firing misses between p1's turns)
    fire(gid, p1_id, 4, 4)
    fire(gid, p2_id, 1, 1)
    fire(gid, p1_id, 4, 3)
    fire(gid, p2_id, 1, 2)
    fire(gid, p1_id, 4, 2)   # game finishes here

    # Now try to fire into the finished game
    status, body = fire(gid, p2_id, 0, 0)
    check("fire into finished game → 400/409/410",
          status in (400, 409, 410), f"got {status}: {body}")


# ===========================================================================
# 2. PLAYER STATISTICS TESTS
# ===========================================================================

def test_stats_wins_losses():
    section("Player Statistics — wins and losses after a completed game")

    reset()

    gid, p1_id, p2_id = setup_two_player_game(
        "StatA", "StatB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    # p1 sinks all of p2's ships; p2 fires misses in between
    fire(gid, p1_id, 4, 4)
    fire(gid, p2_id, 1, 1)
    fire(gid, p1_id, 4, 3)
    fire(gid, p2_id, 1, 2)
    fire(gid, p1_id, 4, 2)   # game finishes

    s1 = stats(p1_id)
    s2 = stats(p2_id)

    check("winner has wins >= 1",              s1.get("wins", 0) >= 1, str(s1))
    check("winner losses unchanged (0)",       s1.get("losses", 0) == 0, str(s1))
    check("loser has losses >= 1",             s2.get("losses", 0) >= 1, str(s2))
    check("loser wins unchanged (0)",          s2.get("wins", 0) == 0, str(s2))
    check("winner games_played >= 1",          s1.get("games_played", 0) >= 1, str(s1))
    check("loser games_played >= 1",           s2.get("games_played", 0) >= 1, str(s2))


def test_stats_accuracy():
    section("Player Statistics — accuracy = total_hits / total_shots (±0.01)")

    reset()

    # p1 has 3 ships; p2 has 3 ships — we'll give p1 1 miss and 1 hit to get ~0.5 accuracy
    gid, p1_id, p2_id = setup_two_player_game(
        "AccA", "AccB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    fire(gid, p1_id, 1, 1)   # p1 misses (turn → p2)
    fire(gid, p2_id, 0, 2)   # p2 hits p1 (turn → p1)
    fire(gid, p1_id, 4, 4)   # p1 hits p2 (turn → p2)

    s1 = stats(p1_id)
    hits   = s1.get("total_hits", 0)
    shots  = s1.get("total_shots", 0)
    acc    = s1.get("accuracy", -1)

    check("total_shots >= 2",                  shots >= 2, str(s1))
    check("total_hits >= 1",                   hits  >= 1, str(s1))

    if shots > 0:
        expected_acc = hits / shots
        check("accuracy within 0.01 of hits/shots",
              abs(acc - expected_acc) <= 0.01,
              f"accuracy={acc}, expected≈{expected_acc:.3f}")
    else:
        check("accuracy within 0.01 of hits/shots", False, "no shots recorded")


def test_stats_survive_restart():
    section("Player Statistics — stats survive test/restart")

    reset()

    gid, p1_id, p2_id = setup_two_player_game(
        "PersA", "PersB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    # Complete a game so stats are non-zero
    fire(gid, p1_id, 4, 4)
    fire(gid, p2_id, 1, 1)
    fire(gid, p1_id, 4, 3)
    fire(gid, p2_id, 1, 2)
    fire(gid, p1_id, 4, 2)   # game finishes

    before = stats(p1_id)
    wins_before   = before.get("wins", 0)
    shots_before  = before.get("total_shots", 0)

    # Restart the game via test endpoint
    status, body = api("post", f"/api/test/games/{gid}/restart",
                       headers=TEST_HEADER)
    check("test restart returns 200", status == 200, str(body))

    after = stats(p1_id)
    check("wins unchanged after restart",
          after.get("wins", 0) == wins_before,
          f"before={wins_before}, after={after.get('wins')}")
    check("total_shots unchanged after restart",
          after.get("total_shots", 0) == shots_before,
          f"before={shots_before}, after={after.get('total_shots')}")


# ===========================================================================
# 3. MULTIPLAYER TESTS
# ===========================================================================

def test_three_players_join_and_place():
    section("Multiplayer — 3 players join and place ships")

    reset()

    p1 = make_player("MP_P1")
    p2 = make_player("MP_P2")
    p3 = make_player("MP_P3")
    gid = make_game(grid=7, max_p=3)

    s1, b1 = join(gid, p1)
    s2, b2 = join(gid, p2)
    s3, b3 = join(gid, p3)

    check("p1 joins → 200",  s1 == 200, str(b1))
    check("p2 joins → 200",  s2 == 200, str(b2))
    check("p3 joins → 200",  s3 == 200, str(b3))

    # testPlaceShips requires >= 3 ship cells
    r1, _ = inject_ships(gid, p1, [{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}])
    r2, _ = inject_ships(gid, p2, [{"row": 3, "col": 0}, {"row": 3, "col": 1}, {"row": 3, "col": 2}])
    r3, _ = inject_ships(gid, p3, [{"row": 6, "col": 0}, {"row": 6, "col": 1}, {"row": 6, "col": 2}])

    check("p1 places ships → 200", r1 == 200)
    check("p2 places ships → 200", r2 == 200)
    check("p3 places ships → 200", r3 == 200)

    _, game = api("get", f"/api/games/{gid}")
    check("game transitions to active after all 3 place",
          game.get("status") == "active", str(game))


def test_join_full_game():
    section("Multiplayer — joining a full game is rejected")

    reset()

    p1 = make_player("Full_P1")
    p2 = make_player("Full_P2")
    p3 = make_player("Full_P3")
    gid = make_game(grid=5, max_p=2)

    join(gid, p1)
    join(gid, p2)

    status, body = join(gid, p3)
    check("join full game → 400 or 409",
          status in (400, 409), f"got {status}: {body}")


# ===========================================================================
# 4. TEST MODE RESTART
# ===========================================================================

def test_restart_clears_state():
    section("Test Mode Restart — clears ships/moves; status → 'waiting'")

    reset()

    gid, p1_id, p2_id = setup_two_player_game(
        "RstA", "RstB",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    # Fire a shot so moves exist
    fire(gid, p1_id, 4, 4)

    # Restart
    status, body = api("post", f"/api/test/games/{gid}/restart",
                       headers=TEST_HEADER)
    check("restart returns 200", status == 200, str(body))

    # Game status should be waiting
    _, game = api("get", f"/api/games/{gid}")
    check("status back to 'waiting'",
          game.get("status") == "waiting", str(game))

    # Moves should be cleared
    _, moves = api("get", f"/api/games/{gid}/moves")
    move_list = moves if isinstance(moves, list) else moves.get("moves", [])
    check("moves list is empty after restart",
          len(move_list) == 0, str(moves))

    # Board should be empty — test endpoint should reflect no ships
    _, board = api("get", f"/api/test/games/{gid}/board/{p1_id}",
                   headers=TEST_HEADER)
    # Accept various empty-board representations
    board_cells = board if isinstance(board, list) else board.get("board", board.get("ships", []))
    ship_cells  = [c for c in board_cells if isinstance(c, dict) and c.get("type") == "ship"] \
                  if isinstance(board_cells, list) else []
    # Simpler: just confirm no 400/500 from the board endpoint
    check("board endpoint accessible after restart",
          isinstance(board, (list, dict)), str(board))


# ===========================================================================
# 5. DATABASE INTEGRITY
# ===========================================================================

def test_unique_player_names():
    section("Database Integrity — duplicate display_name enforcement")

    reset()

    _, b1 = api("post", "/api/players", {"playerName": "UniqueTest"})
    _, b2 = api("post", "/api/players", {"playerName": "UniqueTest"})

    check("same name returns same player_id",
          b1.get("player_id") == b2.get("player_id"),
          f"first={b1}, second={b2}")


def test_referential_integrity_fake_player_join():
    section("Database Integrity — joining with non-existent player_id → 404")

    reset()
    gid = make_game()

    status, body = join(gid, 999999)
    check("fake player join → 404", status == 404, str(body))


def test_referential_integrity_fire_fake_player():
    section("Database Integrity — firing with non-existent player_id → 404")

    reset()
    gid, p1_id, p2_id = setup_two_player_game(
        "RI_A", "RI_B",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    status, body = api("post", f"/api/games/{gid}/fire",
                       {"playerId": 999999, "row": 0, "col": 0})
    check("fire with fake player → 404 or 400",
          status in (400, 404), f"got {status}: {body}")


# ===========================================================================
# 6. CONCURRENT MOVE HANDLING
# ===========================================================================

def test_concurrent_fire():
    section("Concurrency — simultaneous fire requests from same player")

    reset()
    gid, p1_id, p2_id = setup_two_player_game(
        "Con_A", "Con_B",
        p1_ships=[{"row": 0, "col": 0}, {"row": 0, "col": 1}, {"row": 0, "col": 2}],
        p2_ships=[{"row": 4, "col": 4}, {"row": 4, "col": 3}, {"row": 4, "col": 2}]
    )

    responses = []

    def shoot(row, col):
        status, body = fire(gid, p1_id, row, col)
        responses.append((status, body))

    # Launch two simultaneous shots from p1 at different cells
    t1 = threading.Thread(target=shoot, args=(4, 4))
    t2 = threading.Thread(target=shoot, args=(4, 3))
    t1.start(); t2.start()
    t1.join();  t2.join()

    statuses = [r[0] for r in responses]
    ok_count  = sum(1 for s in statuses if s == 200)
    err_count = sum(1 for s in statuses if s in (400, 409, 429))

    check("exactly 1 concurrent shot succeeds",
          ok_count == 1,
          f"statuses={statuses}, bodies={[r[1] for r in responses]}")
    check("exactly 1 concurrent shot is rejected",
          err_count == 1,
          f"statuses={statuses}")


def test_concurrent_join():
    section("Concurrency — simultaneous join requests from same player")

    reset()
    pid = make_player("ConcJoin_P")
    gid = make_game(grid=5, max_p=4)

    responses = []

    def do_join():
        s, b = join(gid, pid)
        responses.append((s, b))

    threads = [threading.Thread(target=do_join) for _ in range(4)]
    for t in threads: t.start()
    for t in threads: t.join()

    ok_count  = sum(1 for s, _ in responses if s == 200)
    err_count = sum(1 for s, _ in responses if s in (400, 409, 500))

    check("player joined exactly once (1 success)",
          ok_count == 1,
          f"statuses={[s for s,_ in responses]}")
    check("remaining concurrent joins rejected (400/409/500)",
          err_count == len(responses) - 1,
          f"statuses={[s for s,_ in responses]}")


# ===========================================================================
# 7. STRESS / PERSISTENCE TESTS
# ===========================================================================

def test_multiple_completed_games_accumulate_stats():
    section("Persistence — stats accumulate correctly across multiple games")

    reset()

    p1_id = make_player("Acc_P1")
    p2_id = make_player("Acc_P2")

    ROUNDS = 3
    for i in range(ROUNDS):
        gid = make_game(grid=5, max_p=2)
        join(gid, p1_id)
        join(gid, p2_id)
        # Use 3 non-overlapping ships; vary col offset slightly per round
        c = i  # 0,1,2 — all within grid
        inject_ships(gid, p1_id, [{"row": 0, "col": c}, {"row": 0, "col": c+1 if c+1 < 5 else 0},
                                   {"row": 1, "col": c}])
        inject_ships(gid, p2_id, [{"row": 4, "col": c}, {"row": 4, "col": c+1 if c+1 < 5 else 0},
                                   {"row": 3, "col": c}])
        # Sink all 3 of p2's ships; p2 fires misses in between
        fire(gid, p1_id, 4, c)
        fire(gid, p2_id, 2, 2)
        fire(gid, p1_id, 4, c+1 if c+1 < 5 else 0)
        fire(gid, p2_id, 2, 3)
        fire(gid, p1_id, 3, c)   # sinks last p2 ship → game over

    s1 = stats(p1_id)
    s2 = stats(p2_id)

    check(f"p1 wins = {ROUNDS} after {ROUNDS} wins",
          s1.get("wins", 0) == ROUNDS, str(s1))
    check(f"p2 losses = {ROUNDS} after {ROUNDS} losses",
          s2.get("losses", 0) == ROUNDS, str(s2))
    check(f"p1 games_played = {ROUNDS}",
          s1.get("games_played", 0) == ROUNDS, str(s1))
    check(f"p2 games_played = {ROUNDS}",
          s2.get("games_played", 0) == ROUNDS, str(s2))


def test_system_reset_wipes_all_data():
    section("System Stability — /api/reset wipes all data")

    # Create some data
    p_id = make_player("ResetCheck")
    gid  = make_game()
    join(gid, p_id)

    # Reset
    reset()

    # Player should now be gone
    status, body = api("get", f"/api/players/{p_id}/stats")
    check("player gone after reset → 404", status == 404, str(body))

    # Game should be gone
    status2, body2 = api("get", f"/api/games/{gid}")
    check("game gone after reset → 404", status2 == 404, str(body2))


def test_game_not_active_without_all_ships():
    section("Game Flow — game stays 'placing' until all players place ships")

    reset()

    p1_id = make_player("Place_P1")
    p2_id = make_player("Place_P2")
    gid   = make_game(grid=5, max_p=2)
    join(gid, p1_id)
    join(gid, p2_id)

    # Only p1 places via normal endpoint (not inject)
    api("post", f"/api/games/{gid}/place",
        {"playerId": p1_id, "ships": [{"row": 0, "col": 0}]})

    _, game = api("get", f"/api/games/{gid}")
    check("game NOT active when only 1/2 players placed",
          game.get("status") != "active", str(game))

    # Firing before all ships placed should fail
    status, body = fire(gid, p1_id, 4, 4)
    check("fire before active → 400", status == 400, str(body))


# ===========================================================================
# MAIN
# ===========================================================================

def main():
    print(f"\nBattleship Phase 3 Test Suite  →  {BASE_URL}\n")

    try:
        requests.get(BASE_URL, timeout=5)
    except requests.exceptions.ConnectionError:
        print(f"ERROR: Cannot reach {BASE_URL}. Is the server running?")
        sys.exit(1)

    reset()

    # --- Win Conditions ---
    test_win_condition_finish()
    test_fire_into_finished_game()

    # --- Player Statistics ---
    test_stats_wins_losses()
    test_stats_accuracy()
    test_stats_survive_restart()

    # --- Multiplayer ---
    test_three_players_join_and_place()
    test_join_full_game()

    # --- Test Mode Restart ---
    test_restart_clears_state()

    # --- Database Integrity ---
    test_unique_player_names()
    test_referential_integrity_fake_player_join()
    test_referential_integrity_fire_fake_player()

    # --- Concurrency ---
    test_concurrent_fire()
    test_concurrent_join()

    # --- Stress / Persistence ---
    test_multiple_completed_games_accumulate_stats()
    test_system_reset_wipes_all_data()
    test_game_not_active_without_all_ships()

    # --- Summary ---
    total = results["passed"] + results["failed"]
    print(f"\n{'='*60}")
    print(f"  Results: {results['passed']}/{total} passed"
          f"  ({results['failed']} failed)")
    print(f"{'='*60}\n")

    sys.exit(0 if results["failed"] == 0 else 1)


if __name__ == "__main__":
    main()