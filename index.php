<?php
declare(strict_types=1);

// ── CORS ─────────────────────────────────────────────────────────────────────
// Allow any origin so your frontend (Claude artifact, localhost, Render, etc.)
// can reach the API without being blocked by the browser.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Password, Authorization');

// Browsers send a preflight OPTIONS request before POST/PUT.
// Respond immediately with 200 so the real request is allowed through.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/router.php';