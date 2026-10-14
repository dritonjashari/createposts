<?php


// ---------------------------------------------------------------------------
// Posting timeslots (admin-defined queue, like Autopost)
// ---------------------------------------------------------------------------
function ensurePublerTimeslotsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db = getDb();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS publer_timeslots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            workspace_id VARCHAR(64) NULL,
            slot_time VARCHAR(5) NOT NULL,
            days VARCHAR(20) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    // One-time per-user migration from the legacy JSON setting. The setting row
    // is deleted once migrated, so this is naturally idempotent (never re-runs).
    $legacy = $db->query("SELECT user_id, setting_value FROM app_settings WHERE setting_key = 'timeslots'")->fetchAll();
    foreach ($legacy as $row) {
        $userId = (int) $row['user_id'];
        $arr = json_decode((string) $row['setting_value'], true);
        if (is_array($arr)) {
            $ins = $db->prepare('INSERT INTO publer_timeslots (user_id, workspace_id, slot_time, days) VALUES (:u, :ws, :t, :d)');
            foreach ($arr as $t) {
                $time = is_string($t) ? $t : (string) ($t['time'] ?? '');
                if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || (int) $m[1] >= 24 || (int) $m[2] >= 60) {
                    continue;
                }
                $days = [];
                if (is_array($t) && !empty($t['days']) && is_array($t['days'])) {
                    foreach ($t['days'] as $d) {
                        $d = (int) $d;
                        if ($d >= 0 && $d <= 6) {
                            $days[] = $d;
                        }
                    }
                    $days = array_values(array_unique($days));
                    sort($days);
                }
                $wsRaw = is_array($t) ? (string) ($t['ws'] ?? '') : '';
                $ins->execute([
                    'u'  => $userId,
                    'ws' => $wsRaw !== '' ? $wsRaw : null,
                    't'  => sprintf('%02d:%02d', (int) $m[1], (int) $m[2]),
                    'd'  => implode(',', $days),
                ]);
            }
        }
        $db->prepare("DELETE FROM app_settings WHERE user_id = :u AND setting_key = 'timeslots'")->execute(['u' => $userId]);
    }
    $ensured = true;
}


/** Map a publer_timeslots row into ['id','time','days'=>[0..6],'ws'=>'' or workspace id]. */
function mapTimeslotRow(array $row): array
{
    $days = ($row['days'] ?? '') !== '' ? array_map('intval', explode(',', (string) $row['days'])) : [];
    return [
        'id'   => (int) $row['id'],
        'time' => (string) $row['slot_time'],
        'days' => $days,
        'ws'   => (string) ($row['workspace_id'] ?? ''),
    ];
}


/**
 * Return the user's timeslots, each as ['id', 'time' => 'HH:MM', 'days' => [0..6], 'ws' => ''|workspace id],
 * where days uses 0=Sun..6=Sat and an empty array means EVERY day, and ws '' means ALL workspaces.
 */
function getTimeslots(int $userId): array
{
    ensurePublerTimeslotsTable();
    $stmt = getDb()->prepare('SELECT id, workspace_id, slot_time, days FROM publer_timeslots WHERE user_id = :u ORDER BY slot_time ASC, id ASC');
    $stmt->execute(['u' => $userId]);
    return array_map('mapTimeslotRow', $stmt->fetchAll());
}


/** Timeslots that apply to a workspace: workspace-specific ones + "all workspaces" ones. */
function getTimeslotsForWorkspace(int $userId, string $workspaceId): array
{
    ensurePublerTimeslotsTable();
    $stmt = getDb()->prepare(
        'SELECT id, workspace_id, slot_time, days FROM publer_timeslots
         WHERE user_id = :u AND (workspace_id IS NULL OR workspace_id = :ws)
         ORDER BY slot_time ASC, id ASC'
    );
    $stmt->execute(['u' => $userId, 'ws' => $workspaceId]);
    return array_map('mapTimeslotRow', $stmt->fetchAll());
}


/** Add a timeslot; returns false on an invalid time or an exact duplicate. */
function addTimeslot(int $userId, string $workspaceId, string $time, array $days): bool
{
    ensurePublerTimeslotsTable();
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || (int) $m[1] >= 24 || (int) $m[2] >= 60) {
        return false;
    }
    $normTime = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    $days = array_values(array_unique(array_filter(array_map('intval', $days), static fn ($d) => $d >= 0 && $d <= 6)));
    sort($days);
    $daysStr = implode(',', $days);
    $ws = $workspaceId !== '' ? $workspaceId : null;

    $db  = getDb();
    $dup = $db->prepare('SELECT COUNT(*) FROM publer_timeslots WHERE user_id = :u AND workspace_id <=> :ws AND slot_time = :t AND days = :d');
    $dup->execute(['u' => $userId, 'ws' => $ws, 't' => $normTime, 'd' => $daysStr]);
    if ((int) $dup->fetchColumn() > 0) {
        return false;
    }

    $ins = $db->prepare('INSERT INTO publer_timeslots (user_id, workspace_id, slot_time, days) VALUES (:u, :ws, :t, :d)');
    $ins->execute(['u' => $userId, 'ws' => $ws, 't' => $normTime, 'd' => $daysStr]);
    return true;
}


/** Remove a timeslot by its id (scoped to the owning user). Returns true if a row was deleted. */
function removeTimeslot(int $userId, int $id): bool
{
    ensurePublerTimeslotsTable();
    $stmt = getDb()->prepare('DELETE FROM publer_timeslots WHERE id = :id AND user_id = :u');
    $stmt->execute(['id' => $id, 'u' => $userId]);
    return $stmt->rowCount() > 0;
}

/** Fallback timezone when an admin hasn't chosen one on the System page yet. */
const TIMESLOT_TZ = 'Europe/Berlin';


/** The timezone timeslots/scheduling use for a user: their own (or their creating admin's) choice, else the default. */
function getAppTimezone(int $userId): string
{
    $ownerId = getSiteOwnerId($userId);
    $tz = trim((string) getSetting($ownerId, 'timezone', ''));
    return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : TIMESLOT_TZ;
}


/** Human label for a timeslot's days. */
function formatTimeslotDays(array $days): string
{
    if (empty($days)) {
        return 'Every day';
    }
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $out = [];
    foreach ($days as $d) {
        $out[] = $names[$d] ?? '?';
    }
    return implode(', ', $out);
}


/** Format a unix timestamp in the given (or app-default) timezone. */
function formatInTimezone(int $ts, string $tz = TIMESLOT_TZ, string $fmt = 'D, M j · H:i'): string
{
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($tz))->format($fmt);
}


/**
 * The next slot datetime strictly after $afterTs, evaluated in the given
 * timezone (DST-aware). Each slot recurs on its configured weekdays (empty =
 * every day). Returns an absolute unix timestamp, or null.
 */
function nextTimeslotTimestamp(array $slots, int $afterTs, string $tz = TIMESLOT_TZ): ?int
{
    if (empty($slots)) {
        return null;
    }
    $tz    = new DateTimeZone($tz);
    $after = (new DateTime('@' . $afterTs))->setTimezone($tz);

    for ($d = 0; $d < 90; $d++) {
        $day = (clone $after)->modify("+$d day");
        $w   = (int) $day->format('w'); // 0=Sun..6=Sat

        // Slots that apply on this weekday, ordered by time.
        $times = [];
        foreach ($slots as $s) {
            if (empty($s['days']) || in_array($w, $s['days'], true)) {
                $times[] = $s['time'];
            }
        }
        sort($times);

        foreach ($times as $hm) {
            [$h, $mi] = array_map('intval', explode(':', $hm));
            $slot = (clone $day)->setTime($h, $mi, 0);
            if ($slot->getTimestamp() > $afterTs) {
                return $slot->getTimestamp();
            }
        }
    }
    return null;
}


/**
 * Unix timestamps (rounded to the minute) that already have a post scheduled
 * in Publer, for this user's active workspace. Used to detect which of our
 * timeslots are actually free before assigning one.
 *
 * A timeslot only assigns the base time to ONE page (the "first page"), so a
 * slot is taken only when THAT page already has a post there. Pass $pageId to
 * restrict the check to that page — otherwise a busy sibling page in the same
 * workspace would wrongly hide slots that are free for the timeslot page.
 */
function fetchPublerOccupiedTimestamps(int $userId, string $pageId = ''): array
{
    $ws = fetchPublerWorkspaceId($userId);
    if ($ws === '') {
        return [];
    }
    // Read EVERY page of the queue: "limit=200" (used here before) isn't a
    // real Publer parameter, so only Publer's first page (~20 posts) was ever
    // seen and busy slots further out looked free — double-booking them.
    // Filtering by account server-side keeps this to a few requests; the
    // seen-id guard covers Publer repeating a page instead of ending.
    $cacheKey = 'occupied|' . $ws . '|' . $pageId . '|' . publerCacheGen($ws);
    $cachedOcc = publerCacheGet($cacheKey, 60);
    if (is_array($cachedOcc)) {
        // JSON turned the int keys into strings; restore them.
        $out = [];
        foreach ($cachedOcc as $ts => $v) {
            $out[(int) $ts] = $v;
        }
        return $out;
    }
    $apiKey = getPublerApiKey($userId);
    $base   = 'posts?state=scheduled' . ($pageId !== '' ? '&' . rawurlencode('account_ids[]') . '=' . rawurlencode($pageId) : '');
    $list   = [];
    $seen   = [];
    for ($page = 0; $page < 40; $page++) {
        $data  = publerHttpGet($base . '&page=' . $page, $ws, $apiKey);
        $batch = is_array($data) ? ($data['posts'] ?? []) : [];
        if (!is_array($batch) || empty($batch)) {
            break;
        }
        $new = 0;
        foreach ($batch as $p) {
            $id = is_array($p) ? (string) ($p['id'] ?? '') : '';
            if ($id !== '' && !isset($seen[$id])) {
                $seen[$id] = true;
                $list[] = $p;
                $new++;
            }
        }
        if ($new === 0) {
            break;
        }
    }
    // Only cache a complete read — never a list cut short by a Publer error.
    $complete = ((int) ($GLOBALS['publer_last_status'] ?? 200)) < 300;

    $occupied = [];
    foreach ($list as $p) {
        if (!is_array($p)) {
            continue;
        }

        // Only this post's own page matters when a specific page was requested.
        if ($pageId !== '') {
            $postPage = (string) ($p['account_id'] ?? '');
            if ($postPage === '' && !empty($p['accounts']) && is_array($p['accounts'])) {
                foreach ($p['accounts'] as $acc) {
                    $postPage = (string) ($acc['id'] ?? $acc['account_id'] ?? '');
                    if ($postPage !== '') {
                        break;
                    }
                }
            }
            if ($postPage !== $pageId) {
                continue;
            }
        }

        $times = [];
        if (!empty($p['scheduled_at'])) {
            $times[] = $p['scheduled_at'];
        }
        foreach ((array) ($p['accounts'] ?? []) as $acc) {
            if (!empty($acc['scheduled_at'])) {
                $times[] = $acc['scheduled_at'];
            }
        }
        foreach ($times as $t) {
            $ts = strtotime((string) $t);
            if ($ts !== false) {
                $occupied[$ts - ($ts % 60)] = true; // minute precision
            }
        }
    }
    if ($complete) {
        publerCacheSet($cacheKey, $occupied);
    }
    return $occupied;
}


/**
 * Like nextTimeslotTimestamp(), but skips any candidate instant already
 * present in $occupiedTimestamps (a Publer post is already scheduled there),
 * moving to the following configured slot instead. Returns null if none of
 * the next $maxTries slots are free.
 */
function nextFreeTimeslotTimestamp(array $slots, int $afterTs, array $occupiedTimestamps, string $tz = TIMESLOT_TZ, int $maxTries = 200): ?int
{
    $cursor = $afterTs;
    for ($i = 0; $i < $maxTries; $i++) {
        $candidate = nextTimeslotTimestamp($slots, $cursor, $tz);
        if ($candidate === null) {
            return null;
        }
        if (!isset($occupiedTimestamps[$candidate])) {
            return $candidate;
        }
        $cursor = $candidate; // that slot is taken — try the one after it
    }
    return null;
}
