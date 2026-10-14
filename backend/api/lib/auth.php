<?php
// JSON-response twins of functions.php's requireLogin()/requireAdmin(). Kept
// separate from those (rather than modifying them) so the legacy server-
// rendered pages keep their redirect-to-login behavior unchanged during the
// migration.

function jsonResponse($data, int $code = 200): void
{
    http_response_code($code);
    // Save session changes BEFORE output starts (see api/bootstrap.php).
    if (function_exists('cpSessionPersist')) {
        cpSessionPersist();
    }
    // Text pulled from WordPress/Publer can contain invalid UTF-8, which would
    // make json_encode() return false and send an empty body.
    echo json_encode(cpIsoTimes($data), JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * MySQL hands back "2026-10-13 10:04:07" in the DATABASE server's own time
 * zone, with no zone attached; a browser then reads it as ITS local time —
 * hours off whenever the two differ (e.g. a US-hosted MySQL, a user in
 * Europe). Every "..._at" value is sent as ISO 8601 with the database's real
 * UTC offset instead, so each browser converts it correctly.
 */
function cpIsoTimes($data, ?string $offset = null)
{
    if (!is_array($data)) {
        return $data;
    }
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = cpIsoTimes($value, $offset);
        } elseif (is_string($value) && is_string($key) && substr($key, -3) === '_at'
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            $offset ??= cpDbUtcOffset();
            $data[$key] = str_replace(' ', 'T', $value) . $offset;
        }
    }
    return $data;
}

/** The database session's current UTC offset, e.g. "+02:00" / "-04:00" (looked up once per request). */
function cpDbUtcOffset(): string
{
    static $offset = null;
    if ($offset === null) {
        $offset = '+00:00';
        try {
            $diff = (string) getDb()->query('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP())')->fetchColumn(); // "-04:00:00"
            if (preg_match('/^(-?)(\d{1,2}):(\d{2})/', $diff, $m)) {
                $offset = ($m[1] === '-' ? '-' : '+') . str_pad($m[2], 2, '0', STR_PAD_LEFT) . ':' . $m[3];
            }
        } catch (\Throwable $e) {
            // keep UTC
        }
    }
    return $offset;
}

function jsonError(int $code, string $message, array $extra = []): void
{
    jsonResponse(['error' => $message] + $extra, $code);
}

function requireLoginApi(): void
{
    if (!isLoggedIn()) {
        jsonError(401, 'Not authenticated');
    }
}

function requireAdminApi(): void
{
    requireLoginApi();
    if (!isAdmin()) {
        jsonError(403, 'Admin access required');
    }
}

/** Decode a JSON request body (for non-multipart POSTs). Empty/invalid body -> []. */
function jsonBody(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}
