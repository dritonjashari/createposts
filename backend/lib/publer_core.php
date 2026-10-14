<?php


// ---------------------------------------------------------------------------
// Publer (read-only helpers for the dashboard)
// ---------------------------------------------------------------------------
function publerHttpGet(string $endpoint, string $workspaceId, string $apiKey)
{
    // Decoded JSON, or null on any failure ($GLOBALS['publer_last_status']
    // holds the HTTP status, e.g. 429 when Publer's rate limit is reached).
    $res = publerRequest('GET', $endpoint, $apiKey, $workspaceId, null, false, 25);
    return ($res['code'] >= 200 && $res['code'] < 300) ? $res['data'] : null;
}




/**
 * The workspace ids a user is allowed to use:
 *  - their own assigned list ('publer_workspaces'), if explicitly restricted,
 *  - else the admin who created them (editors inherit their restriction),
 *  - else every workspace the key can see (unrestricted).
 *
 * Note: the user's *currently active* workspace ('publer_workspace_id') must
 * NOT be used as a stand-in allow-list here — it's just a preference, and
 * treating it as the allowed set would lock a user into their first pick,
 * unable to ever switch to a different workspace again.
 */
function getAllowedWorkspaceIds(int $userId): array
{
    $decode = static function (string $json): array {
        $a = json_decode($json, true);
        return is_array($a) ? array_values(array_filter(array_map('strval', $a), static fn ($v) => $v !== '')) : [];
    };

    // getSetting() returns null only when the row doesn't exist at all — that's
    // the "never configured" case. A stored value of '[]' means an admin
    // explicitly unchecked every workspace for this user (revoking access),
    // which must NOT be treated the same as "not configured" and fall back to
    // an unrestricted list — that was the bug: an editor with zero workspaces
    // selected ended up with access to everything.
    $own = getSetting($userId, 'publer_workspaces', null);
    if ($own !== null) {
        return array_values(array_unique($decode($own)));
    }

    $ownerId = getSiteOwnerId($userId);
    if ($ownerId !== $userId) {
        $ownerRaw = getSetting($ownerId, 'publer_workspaces', null);
        if ($ownerRaw !== null) {
            return array_values(array_unique($decode($ownerRaw)));
        }
    }

    // Nobody has ever configured a restriction — unrestricted default.
    $ids = [];
    foreach (getPublerWorkspaces($userId) as $w) {
        $ids[] = $w['id'];
    }
    return array_values(array_unique($ids));
}


/** The user's currently active workspace (their saved choice if still allowed, else the first allowed). */
function getActiveWorkspaceId(int $userId): string
{
    $allowed = getAllowedWorkspaceIds($userId);
    $active  = (string) getSetting($userId, 'publer_workspace_id', '');
    if ($active !== '' && in_array($active, $allowed, true)) {
        return $active;
    }
    if (!empty($allowed)) {
        return (string) $allowed[0];
    }
    // $allowed is empty — either genuinely no workspaces exist for this API
    // key, or (more likely for an editor) an admin explicitly restricted them
    // to none. Either way there is nothing they're allowed to activate;
    // falling back to an unrestricted "first workspace from the API" here
    // was the bug (it ignored the allow-list and handed back real access).
    return '';
}


/** Switch the user's active workspace (only if it's one they're allowed to use). */
function setActiveWorkspaceId(int $userId, string $workspaceId): bool
{
    if ($workspaceId !== '' && in_array($workspaceId, getAllowedWorkspaceIds($userId), true)) {
        setSetting($userId, 'publer_workspace_id', $workspaceId);
        return true;
    }
    return false;
}


/** Back-compat: everywhere that asks for "the" workspace gets the active one. */
function fetchPublerWorkspaceId(int $userId): string
{
    return getActiveWorkspaceId($userId);
}


/** List Publer workspaces for a user's key: [ ['id'=>..,'name'=>..], ... ]. Cached in session (5 min). */
function getPublerWorkspaces(int $userId): array
{
    // The navbar switcher calls this on every page, so cache to avoid a Publer
    // API round-trip per request. Cleared on API-key change (see system.php).
    $cacheKey = 'publer_ws_list_' . $userId;
    $cached   = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['t'], $cached['data']) && (time() - (int) $cached['t']) < 300) {
        return $cached['data'];
    }

    $apiKey = getPublerApiKey($userId);
    $shared = publerCacheGet('workspaces|' . md5($apiKey), 300);
    if (is_array($shared) && !empty($shared)) {
        $_SESSION[$cacheKey] = ['t' => time(), 'data' => $shared];
        return $shared;
    }
    $data = publerHttpGet('workspaces', '', $apiKey);
    $out = [];
    if (is_array($data)) {
        foreach ($data as $w) {
            if (!empty($w['id'])) {
                $out[] = ['id' => (string) $w['id'], 'name' => (string) ($w['name'] ?? 'Workspace')];
            }
        }
    }
    if (!empty($out)) { // don't cache a transient API failure
        $_SESSION[$cacheKey] = ['t' => time(), 'data' => $out];
        publerCacheSet('workspaces|' . md5($apiKey), $out);
    }
    return $out;
}


/** Only the workspaces (id+name) a user is allowed to use. */
function getAllowedWorkspaces(int $userId): array
{
    $allowed = getAllowedWorkspaceIds($userId);
    $out = [];
    foreach (getPublerWorkspaces($userId) as $w) {
        if (in_array($w['id'], $allowed, true)) {
            $out[] = $w;
        }
    }
    return $out;
}


/**
 * A displayable thumbnail URL for a Publer post's "media" array, or null when
 * there's no media (or no thumbnail could be resolved). Photos carry a single
 * "thumbnail" URL; videos carry a "thumbnails" array of {small, real} pairs
 * plus a "default_thumbnail" index picking which one Publer shows by default.
 */
function publerMediaThumbnail(array $media): ?string
{
    $first = $media[0] ?? null;
    if (!is_array($first)) {
        return null;
    }
    if (!empty($first['thumbnail'])) {
        return (string) $first['thumbnail'];
    }
    if (!empty($first['thumbnails']) && is_array($first['thumbnails'])) {
        $idx   = (int) ($first['default_thumbnail'] ?? 0);
        $thumb = $first['thumbnails'][$idx] ?? $first['thumbnails'][0] ?? null;
        if (is_array($thumb) && !empty($thumb['small'])) {
            return (string) $thumb['small'];
        }
    }
    return null;
}


/** Number of posts currently scheduled in Publer, or null if unavailable. */
function getPublerScheduledCount(int $userId): ?int
{
    $ws = fetchPublerWorkspaceId($userId);
    if ($ws === '') {
        return null;
    }
    $key    = 'count|' . $ws . '|' . publerCacheGen($ws);
    $cached = publerCacheGet($key, 60);
    if ($cached !== null) {
        return (int) $cached;
    }
    $data = publerHttpGet('posts?state=scheduled', $ws, getPublerApiKey($userId));
    if (is_array($data) && isset($data['total'])) {
        publerCacheSet($key, (int) $data['total']);
        return (int) $data['total'];
    }
    return null;
}


/** List of posts currently scheduled in Publer (normalized), newest-scheduled first. Empty if unavailable. */
function fetchPublerScheduledPosts(int $userId, int $limit = 25): array
{
    $ws = fetchPublerWorkspaceId($userId);
    if ($ws === '') {
        return [];
    }
    $key  = 'preview|' . $ws . '|' . publerCacheGen($ws);
    $data = publerCacheGet($key, 60);
    if ($data === null) {
        $data = publerHttpGet('posts?state=scheduled', $ws, getPublerApiKey($userId));
        if (is_array($data)) {
            publerCacheSet($key, $data);
        }
    }
    $list = is_array($data) ? ($data['posts'] ?? $data) : [];
    if (!is_array($list)) {
        return [];
    }

    // Publer's list response has ONE post record per Facebook page — the
    // account is a single top-level "account_id" field, not a nested
    // "accounts"/"networks" wrapper (that shape is specific to the *write*
    // bulk-schedule payload). Publer doesn't return the page's name here, so
    // resolve it from our own workspace page list.
    $fbPages  = fetchPublerFacebookPages($userId, $ws);
    $pageName = [];
    foreach ($fbPages as $fp) {
        $pageName[$fp['id']] = $fp['name'];
    }

    $out = [];
    foreach ($list as $p) {
        if (!is_array($p)) {
            continue;
        }

        $accId = (string) ($p['account_id'] ?? '');

        $out[] = [
            'id'           => (string) ($p['id'] ?? ''),
            'text'         => (string) ($p['text'] ?? ''),
            'account'      => $pageName[$accId] ?? '',
            'scheduled_at' => $p['scheduled_at'] ?? null,
            'state'        => (string) ($p['state'] ?? 'scheduled'),
        ];

        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}


/** List of Facebook pages connected to Publer: [['id','name'], ...]. */
function fetchPublerFacebookPages(int $userId, string $workspaceId = ''): array
{
    $ws = $workspaceId !== '' ? $workspaceId : fetchPublerWorkspaceId($userId);
    if ($ws === '') {
        return [];
    }

    // Called on nearly every Publer-related page load (publer.php, tasks.php,
    // dashboards, system.php) just to show page names — cache it the same way
    // getPublerWorkspaces() already is. Cleared on API-key change (system.php).
    $cacheKey = 'publer_fb_pages_' . $userId . '_' . $ws;
    $cached   = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['t'], $cached['data']) && (time() - (int) $cached['t']) < 300) {
        return $cached['data'];
    }

    $apiKey    = getPublerApiKey($userId);
    $sharedKey = 'accounts|' . $ws . '|' . md5($apiKey);
    $shared    = publerCacheGet($sharedKey, 300);
    if (is_array($shared) && !empty($shared)) {
        $_SESSION[$cacheKey] = ['t' => time(), 'data' => $shared];
        return $shared;
    }
    $data  = publerHttpGet('accounts', $ws, $apiKey);
    $pages = [];
    if (is_array($data)) {
        foreach ($data as $a) {
            if (!empty($a['id']) && strtolower((string) ($a['provider'] ?? '')) === 'facebook') {
                $pages[] = [
                    'id'        => (string) $a['id'],
                    'name'      => (string) ($a['name'] ?? $a['username'] ?? 'Facebook Page'),
                    'social_id' => (string) ($a['social_id'] ?? ''),
                    'connected' => !empty($a['permissions']['can_access']) && empty($a['locked']),
                ];
            }
        }
    }
    if (!empty($pages)) { // don't cache a transient API failure
        $_SESSION[$cacheKey] = ['t' => time(), 'data' => $pages];
        publerCacheSet($sharedKey, $pages);
    }
    return $pages;
}


/** Upper bound for auto-delete: one year, in hours. */
const AUTO_DELETE_MAX_HOURS = 8760;


/** The System page default for auto-delete, in hours (0 = off). */
function defaultAutoDeleteHours(int $ownerId): int
{
    if (getSetting($ownerId, 'auto_delete_enabled', '0') !== '1') {
        return 0;
    }
    return min(AUTO_DELETE_MAX_HOURS, max(1, (int) getSetting($ownerId, 'auto_delete_hours', '24')));
}


/**
 * Auto-delete hours for one schedule submission (0 = off): the form's own
 * choice when it sent one, otherwise the System page default.
 */
function resolveAutoDeleteHours(int $ownerId, array $input): int
{
    if (!array_key_exists('auto_delete', $input)) {
        return defaultAutoDeleteHours($ownerId);
    }
    if (empty($input['auto_delete'])) {
        return 0;
    }
    return min(AUTO_DELETE_MAX_HOURS, max(1, (int) ($input['auto_delete_hours'] ?? 24)));
}


/**
 * The account-level "delete" callback (Publer's Post Callbacks API) that
 * removes a post once it's at least $hours old, or null when $hours is 0.
 * Goes on each bulk.posts[].accounts[] entry. Not supported by Publer for
 * Instagram API, TikTok or Stories — irrelevant here since we only schedule
 * to Facebook pages.
 * https://publer.com/docs/posting/create-posts/content-types/post-callbacks
 */
function publerAutoDeleteCallback(int $hours): ?array
{
    if ($hours <= 0) {
        return null;
    }
    return [
        'conditions' => [
            'relation' => 'AND',
            'clauses'  => ['age' => ['duration' => $hours, 'unit' => 'Hour']],
        ],
        'hide' => false,
    ];
}


// ---------------------------------------------------------------------------
// Publer scheduling helpers (moved here from publer.php so API route handlers
// can call them without loading a page script — publer.php now calls these).
// ---------------------------------------------------------------------------

/**
 * Make a request to the Publer API (GET/POST, JSON or multipart).
 * Returns ['code' => int, 'error' => string, 'data' => mixed, 'raw' => string].
 */
function publerRequest(string $method, string $endpoint, string $apiKey, ?string $workspaceId = null, $payload = null, bool $multipart = false, int $timeout = 120): array
{
    // Publer allows 100 requests per 2-minute window for the whole account
    // (every user and job of the team share it). The background worker sets
    // $GLOBALS['publer_wait_on_rate_limit'] so a job waits for the window to
    // reset and carries on, instead of failing pages; interactive requests
    // return the 429 straight away so the screen can say what happened.
    for ($attempt = 0; ; $attempt++) {
        $res = publerRequestOnce($method, $endpoint, $apiKey, $workspaceId, $payload, $multipart, $timeout);
        if ($res['code'] !== 429 || empty($GLOBALS['publer_wait_on_rate_limit']) || $attempt >= 2) {
            return $res;
        }
        $reset = (int) ($res['rate_reset'] ?? 0);
        sleep(max(5, min(125, $reset > 0 ? $reset - time() + 1 : 60)));
    }
}


/** One HTTP call to Publer; see publerRequest(). */
function publerRequestOnce(string $method, string $endpoint, string $apiKey, ?string $workspaceId, $payload, bool $multipart, int $timeout): array
{
    $headers = [
        'Authorization: Bearer-API ' . $apiKey,
        'Accept: application/json',
    ];
    if ($workspaceId !== null && $workspaceId !== '') {
        $headers[] = 'Publer-Workspace-Id: ' . $workspaceId;
    }

    $rateReset = 0;
    $ch = curl_init(PUBLER_API_BASE . ltrim($endpoint, '/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$rateReset): int {
        if (stripos($line, 'X-RateLimit-Reset:') === 0) {
            $rateReset = (int) trim(substr($line, 18));
        }
        return strlen($line);
    });

    $upperMethod = strtoupper($method);
    if ($upperMethod === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); // array with CURLFile
        } else {
            $headers[] = 'Content-Type: application/json';
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        }
    } elseif ($upperMethod === 'PATCH' || $upperMethod === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        $headers[] = 'Content-Type: application/json';
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
    } elseif ($upperMethod === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
    }

    // Local XAMPP installs often lack an up-to-date CA bundle.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw   = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $GLOBALS['publer_last_status'] = $code;

    return [
        'code'       => $code,
        'error'      => $error,
        'data'       => $raw === false ? null : json_decode($raw, true),
        'raw'        => $raw === false ? '' : (string) $raw,
        'rate_reset' => $rateReset,
    ];
}


/** Friendly message for a failed Publer read, by HTTP status. */
function publerErrorMessage(int $code): string
{
    if ($code === 429) {
        return 'Publer is busy right now — your Publer account allows 100 requests every 2 minutes and that limit was reached. Please try again in a minute.';
    }
    if ($code === 401 || $code === 403) {
        return 'Publer refused the request (HTTP ' . $code . '). Check the Publer API key on the System page.';
    }
    return $code === 0 ? 'Could not connect to Publer. Please try again.' : 'Publer returned an error (HTTP ' . $code . '). Please try again.';
}


// ---------------------------------------------------------------------------
// Small shared cache for Publer reads. The 100-requests-per-2-minutes limit
// is shared by the whole team, so the same queue/accounts data shouldn't be
// fetched again by every user and every page load. Entries are keyed by
// workspace + a per-workspace "generation" that is bumped whenever the app
// changes something there (schedule, edit, reschedule, delete, repost), so a
// change is always visible immediately.
// ---------------------------------------------------------------------------

function publerCacheFile(string $key): string
{
    static $dir = null;
    if ($dir === null) {
        // Inside the app (blocked from the web by lib/.htaccess), not the
        // system temp dir: the cron worker and the web server may each have
        // their own temp dir, and a job's "queue changed" bump must reach
        // the web side.
        $dir = __DIR__ . '/uploads/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
    return $dir . '/' . md5($key) . '.json';
}

/** Cached value, or null when missing/older than $ttl seconds. */
function publerCacheGet(string $key, int $ttl)
{
    $f = publerCacheFile($key);
    if (!is_file($f) || filemtime($f) < time() - $ttl) {
        return null;
    }
    $v = json_decode((string) @file_get_contents($f), true);
    return is_array($v) && array_key_exists('v', $v) ? $v['v'] : null;
}

function publerCacheSet(string $key, $value): void
{
    @file_put_contents(publerCacheFile($key), json_encode(['v' => $value]), LOCK_EX);
}

/** Current cache generation of a workspace. */
function publerCacheGen(string $workspaceId): int
{
    return (int) (publerCacheGet('gen|' . $workspaceId, 86400 * 30) ?? 0);
}

/** Something changed in this workspace's queue: make every cached read of it stale. */
function publerCacheBump(string $workspaceId): void
{
    if ($workspaceId !== '') {
        publerCacheSet('gen|' . $workspaceId, publerCacheGen($workspaceId) + 1);
    }
}


/**
 * Upload an image OR video to Publer.
 * Returns the media object (id, type, thumbnails, ...) or null (with $error set on failure).
 */
/**
 * Store an uploaded $_FILES['media']-style entry to disk once. The stored
 * copy is later re-marked and re-uploaded separately per Facebook page (see
 * publerUploadStoredMedia()) so every page gets a differently-stamped,
 * differently-hashed copy of the same source image, instead of every page
 * sharing one Publer media id (and therefore an identical file).
 * Returns null with $error === '' when no file was chosen; null with $error
 * set on failure.
 */
function storeUploadedMediaFile(array $file, string &$error): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // nothing chosen — not an error
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'The file is larger than the server upload limit (see upload_max_filesize / post_max_size in php.ini).'
            : 'The file failed to upload (code ' . $file['error'] . ').';
        return null;
    }

    // Trust the file's actual bytes, never its name or the browser's claimed
    // type: these files land under the web root, so keeping a user-chosen
    // ".php" name would let anyone with an account run code on the server.
    $type = detectMediaFileType($file['tmp_name']);
    if ($type === null) {
        $error = 'Only JPG, PNG, GIF or WebP images and MP4, MOV or WebM videos can be attached.';
        return null;
    }
    $stored = mediaUploadDir() . 'media-' . bin2hex(random_bytes(12)) . '.' . $type['ext'];
    if (!move_uploaded_file($file['tmp_name'], $stored)) {
        $error = 'Could not store the uploaded file on the server.';
        return null;
    }

    return ['path' => $stored, 'mime' => $type['mime'], 'name' => safeMediaName((string) $file['name'], $type['ext'])];
}


/** Image/video types accepted for Facebook posts: mime => file extension. */
const MEDIA_FILE_TYPES = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v',
];


/** Real type of a media file from its contents: ['mime', 'ext'] or null if not an allowed image/video. */
function detectMediaFileType(string $path): ?array
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $path);
        finfo_close($fi);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($path);
    }
    if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
        return null; // claims to be an image but isn't decodable
    }
    return isset(MEDIA_FILE_TYPES[$mime]) ? ['mime' => $mime, 'ext' => MEDIA_FILE_TYPES[$mime]] : null;
}


/** A tidy display name for Publer's media library, always ending in the real extension. */
function safeMediaName(string $original, string $ext): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME));
    $base = trim(substr((string) $base, 0, 80), '-.');
    return ($base !== '' ? $base : 'media') . '.' . $ext;
}


/** Folder holding media waiting to be sent to Publer (blocked from direct web access by .htaccess). */
function mediaUploadDir(): string
{
    $dir = __DIR__ . '/uploads/publer/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}


/**
 * Download a post's media (e.g. from Publer's CDN, for "Repost") into the
 * upload folder, like an uploaded file. Public hosts only, size-capped, and
 * the type checked from the downloaded bytes. Null with $error on failure.
 */
function storeRemoteMediaFile(string $url, string &$error): ?array
{
    $tmp = mediaUploadDir() . 'download-' . bin2hex(random_bytes(12)) . '.part';
    $err = null;
    if (!scrapeDownloadToFile($url, $tmp, 300 * 1024 * 1024, $err)) {
        @unlink($tmp);
        $error = 'Could not download the post\'s media: ' . $err;
        return null;
    }
    $type = detectMediaFileType($tmp);
    if ($type === null) {
        @unlink($tmp);
        $error = 'The post\'s media is not a supported image or video.';
        return null;
    }
    $stored = substr($tmp, 0, -5) . '.' . $type['ext'];
    if (!@rename($tmp, $stored)) {
        @unlink($tmp);
        $error = 'Could not store the downloaded media on the server.';
        return null;
    }
    return ['path' => $stored, 'mime' => $type['mime'], 'name' => safeMediaName(basename((string) parse_url($url, PHP_URL_PATH)), $type['ext'])];
}


/** System default: stamp each page's copy of a photo with random white pixels (unique file per page). */
function defaultAddPixels(int $ownerId): bool
{
    return getSetting($ownerId, 'default_add_pixels', '1') === '1';
}


/**
 * Upload a file stored by storeUploadedMediaFile() to Publer. For images,
 * stamps a fresh random white-pixel marker first (unless $mark is false) so
 * repeated uploads of the same source file each get a different hash —
 * used to give every Facebook page its own distinct copy of the image.
 * $alpha: see addUniquenessMarker().
 */
function publerUploadStoredMedia(array $stored, string $apiKey, string $workspaceId, string &$error, bool $mark = true, int $alpha = 13): ?array
{
    $upload = $stored['path'];
    if ($mark && str_starts_with($stored['mime'], 'image/')) {
        $upload = addUniquenessMarker($stored['path'], $stored['mime'], $alpha);
    }

    $res = publerRequest('POST', 'media', $apiKey, $workspaceId, [
        'file' => new CURLFile($upload, $stored['mime'], $stored['name']),
    ], true);

    if ($upload !== $stored['path']) {
        @unlink($upload);
    }

    if ($res['code'] === 200 && !empty($res['data']['id'])) {
        return $res['data'];
    }

    $detail = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
    $error  = 'Media upload to Publer failed (HTTP ' . $res['code'] . '): ' . $detail;
    return null;
}
