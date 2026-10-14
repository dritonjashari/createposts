<?php
// ---------------------------------------------------------------------------
// JSON API bootstrap.
//
// The SPA is a separate app (possibly on a different domain — Namecheap
// shared hosting may split frontend/backend across hosts), so auth can't rely
// on a same-site cookie. Instead the bearer token IS a PHP session id:
//   - POST /api/auth/login generates one via session_create_id() and returns
//     it in the JSON body; the frontend stores it and sends it back as
//     `Authorization: Bearer <token>` on every request.
//   - Here, we read that header, disable PHP's cookie transport, and adopt
//     the same session id — so every $_SESSION read/write already scattered
//     through functions.php (workspace-switch cache, GA token cache, publish
//     replay-guard tokens, ...) keeps working completely unchanged.
// ---------------------------------------------------------------------------

define('CP_DEFER_SESSION_START', true);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

require_once __DIR__ . '/../config.php';

if ($origin !== '' && in_array($origin, API_ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Every response here is JSON. A PHP notice/warning printed into the body
// (XAMPP ships display_errors=On) corrupts it, and the SPA then can't parse a
// perfectly good response — log them instead of displaying them.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');
// No session-generated cache headers: the session may be re-opened after the
// response has started (see cpSessionPersist()), when no header can be sent
// and PHP refuses to change session settings. Send the no-cache headers once
// ourselves instead.
ini_set('session.cache_limiter', '');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
$token = '';
if ($authHeader !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m)) {
    $token = trim($m[1]);
}

// Only adopt a token that looks like a real session id PHP would have issued
// (session_create_id() output) — never let a client hand us an arbitrary
// string and have session_id() silently accept it.
if ($token !== '' && preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $token)) {
    session_id($token);
}

// PHP's file session handler holds an exclusive lock on the session for the
// whole request. The SPA fires several requests in parallel with the same
// token (dashboard, sidebar, notifications, announcements, ...), and some of
// them call the Publer API — so with the lock held, every other request
// queued behind the slowest one and the app looked frozen after login.
// Read the session and release the lock immediately instead; any changes a
// handler makes to $_SESSION are merged back in by cpSessionPersist(), which
// jsonResponse() calls before sending the body (and shutdown as a fallback).
session_start(['read_and_close' => true]);
$GLOBALS['cp_session_snapshot'] = $_SESSION;

/** Re-open the (already closed) session for writing. */
function cpSessionReopen(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    return @session_start();
}

/** Mark the session as intentionally wiped (logout) so it isn't re-saved. */
function cpSessionForget(): void
{
    $_SESSION = [];
    $GLOBALS['cp_session_snapshot'] = [];
}

/**
 * Write this request's $_SESSION changes back to the session store. Applies
 * only this request's own changes on top of a fresh read, so a parallel
 * request's writes aren't clobbered. Safe to call more than once.
 */
function cpSessionPersist(): void
{
    $before = $GLOBALS['cp_session_snapshot'] ?? [];
    $after  = $_SESSION ?? [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        $GLOBALS['cp_session_snapshot'] = $_SESSION;
        return;
    }
    if ($after === $before || !cpSessionReopen()) {
        return;
    }
    foreach ($after as $k => $v) {
        if (!array_key_exists($k, $before) || $before[$k] !== $v) {
            $_SESSION[$k] = $v;
        }
    }
    foreach (array_keys($before) as $k) {
        if (!array_key_exists($k, $after)) {
            unset($_SESSION[$k]);
        }
    }
    session_write_close();
    $GLOBALS['cp_session_snapshot'] = $_SESSION;
}

register_shutdown_function('cpSessionPersist');

require_once __DIR__ . '/../functions.php';

header('Content-Type: application/json');
