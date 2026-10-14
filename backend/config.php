<?php
// Pin PHP's own default timezone explicitly rather than relying on whatever
// the hosting server's php.ini happens to set (varies a lot — many hosts
// default to UTC). Everything computing a schedule/timeslot time already
// goes through getAppTimezone()/DateTimeZone explicitly, but this is the
// fallback for any bare date()/time()/strtotime() call, and matches
// TIMESLOT_TZ (lib/publer_timeslots.php) as the app's overall default.
date_default_timezone_set('Europe/Berlin');

// Old server-rendered pages want a normal cookie session started immediately.
// The API front controller (api/bootstrap.php) needs to configure the session
// (token-based id, cookies disabled) *before* it starts — it defines
// CP_DEFER_SESSION_START ahead of requiring this file to skip this auto-start.
if (session_status() === PHP_SESSION_NONE && !defined('CP_DEFER_SESSION_START')) {
    session_start();
}

// The database login lives in config.local.php, which is git-ignored so it
// never ends up in the repository and a `git pull` never overwrites it.
// `npm run build` (repository root) creates it from config.local.example.php.
if (!is_file(__DIR__ . '/config.local.php')) {
    $cpConfigError = 'Server not configured: run `npm run build` in the repository root (or copy backend/config.local.example.php to backend/config.local.php) and fill in the database login.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $cpConfigError . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => $cpConfigError]);
    }
    exit(1);
}
require_once __DIR__ . '/config.local.php';

// Everything below is a default that config.local.php may override by
// defining the same constant first.

defined('APP_NAME') || define('APP_NAME', 'CreatePosts');

// Origins allowed to call the JSON API cross-origin (see api/bootstrap.php).
// The deployed app calls the API on its own domain and needs none of this;
// these are the Vite dev server (`npm run dev`) origins.
defined('API_ALLOWED_ORIGINS') || define('API_ALLOWED_ORIGINS', [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
]);

// Shared secrets that let the app kick the background workers over HTTP
// right after queuing a job. Derived from the database login by default, so
// every install gets its own without any setup.
defined('PUBLISH_WORKER_TOKEN')  || define('PUBLISH_WORKER_TOKEN', hash_hmac('sha256', 'publish-worker', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS));
defined('SCHEDULE_WORKER_TOKEN') || define('SCHEDULE_WORKER_TOKEN', hash_hmac('sha256', 'schedule-worker', DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASS));

// Publer API. PUBLER_API_KEY is only the fallback used when an account has
// no key of its own saved (lib/auth.php).
defined('PUBLER_API_KEY')  || define('PUBLER_API_KEY', '');
defined('PUBLER_API_BASE') || define('PUBLER_API_BASE', 'https://app.publer.com/api/v1/');

// Fastest OpenAI model used for paraphrasing (low latency).
defined('PARAPHRASE_MODEL') || define('PARAPHRASE_MODEL', 'gpt-4o-mini');

// Publish to Websites processing mode.
//   true  = queue the publish and process it in the background (worker + progress page)
//   false = publish synchronously in the request (the previous behaviour)
// Flip this to false to instantly revert to the old synchronous flow.
defined('BACKGROUND_PUBLISHING') || define('BACKGROUND_PUBLISHING', true);

// Schedule Posts (Publer) processing mode — same idea as BACKGROUND_PUBLISHING.
//   true  = queue the schedule request and process it page-by-page in the
//           background (worker + progress page), so the UI can show real
//           "X of Y pages" progress instead of one long silent wait.
//   false = process synchronously in the request (the previous behaviour).
defined('BACKGROUND_SCHEDULING') || define('BACKGROUND_SCHEDULING', true);
