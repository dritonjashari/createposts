<?php
// ---------------------------------------------------------------------------
// Dashboard statistics: activity per day, period totals vs the previous
// period, success rate, per-member and per-website breakdowns.
//
// Everything comes from this app's own tables with a handful of indexed
// range queries (no Publer calls), bucketed into days in the app timezone.
// Scope is the whole team ($userId = null, admins) or one member (editors).
// ---------------------------------------------------------------------------

/** Day keys (Y-m-d) of the last $days days ending today, in $tz, plus the unix start of the first day. */
function dashboardDays(int $days, DateTimeZone $tz): array
{
    $day = new DateTime('today', $tz);
    $day->modify('-' . ($days - 1) . ' days');
    $keys = [];
    $starts = []; // unix start of each day (DST-correct, since DateTime walks local days)
    for ($i = 0; $i < $days; $i++) {
        $keys[] = $day->format('Y-m-d');
        $starts[] = $day->getTimestamp();
        $day->modify('+1 day');
    }
    return [$keys, $starts];
}


/** Count successes / failures in a job's per-site or per-page results (retried failures don't count). */
function dashboardResultCounts(?string $json): array
{
    $results = json_decode((string) $json, true);
    $ok = 0;
    $failed = 0;
    if (is_array($results)) {
        foreach ($results as $r) {
            if (!is_array($r) || !empty($r['pending'])) {
                continue;
            }
            if (!empty($r['success'])) {
                $ok++;
            } elseif (empty($r['retried'])) {
                $failed++;
            }
        }
    }
    return [$ok, $failed];
}


/**
 * @param int      $ownerId team owner (getSiteOwnerId)
 * @param int|null $userId  null = whole team, else only this member's work
 * @param int      $days    7 or 30
 */
function dashboardStats(int $ownerId, ?int $userId, int $days, string $timezone): array
{
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    [$dayKeys, $dayStarts] = dashboardDays($days, $tz);
    $periodStart = $dayStarts[0];
    $periodEnd   = (new DateTime('tomorrow', $tz))->getTimestamp();
    $prevStart = (new DateTime('@' . $periodStart))->setTimezone($tz)->modify('-' . $days . ' days')->getTimestamp();
    $todayKey  = end($dayKeys);
    // Day of a timestamp inside the period: binary search over the day starts
    // (no DateTime object per row — thousands of rows stay a few ms).
    $dayOf = static function (int $ts) use ($dayStarts, $dayKeys): string {
        $lo = 0;
        $hi = count($dayStarts) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($dayStarts[$mid] <= $ts) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $dayKeys[$lo];
    };

    $series = [];
    foreach ($dayKeys as $k) {
        $series[$k] = ['date' => $k, 'web' => 0, 'social' => 0, 'failed' => 0];
    }
    $period = ['web' => 0, 'social' => 0, 'failed' => 0];
    $prev   = ['web' => 0, 'social' => 0, 'failed' => 0];
    $today  = ['web' => 0, 'social' => 0];
    $members = []; // userId => [web, social, failed]
    $sitePosts = []; // siteId => count in period

    $bump = static function (string $kind, int $ts, ?int $uid, int $n) use (&$series, &$period, &$prev, &$today, &$members, $periodStart, $periodEnd, $prevStart, $dayOf, $todayKey): void {
        if ($n <= 0 || $ts < $prevStart || $ts >= $periodEnd) {
            return; // outside both periods (or in the future: clock skew)
        }
        if ($ts < $periodStart) {
            $prev[$kind] += $n;
            return;
        }
        $key = $dayOf($ts);
        $series[$key][$kind] += $n;
        $period[$kind] += $n;
        if ($key === $todayKey && $kind !== 'failed') {
            $today[$kind] += $n;
        }
        if ($uid !== null) {
            $members[$uid] ??= ['web' => 0, 'social' => 0, 'failed' => 0];
            $members[$uid][$kind] += $n;
        }
    };

    $db = getDb();
    $userSql = $userId !== null ? ' AND posted_by = :uid' : '';
    $params  = ['oid' => $ownerId, 'since' => $prevStart] + ($userId !== null ? ['uid' => $userId] : []);

    // Website posts that went out (one row per site).
    $stmt = $db->prepare(
        'SELECT UNIX_TIMESTAMP(created_at) AS ts, posted_by, site_id FROM published_posts
         WHERE user_id = :oid AND created_at >= FROM_UNIXTIME(:since)' . $userSql
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ts = (int) $r['ts'];
        $bump('web', $ts, $r['posted_by'] !== null ? (int) $r['posted_by'] : null, 1);
        if ($ts >= $periodStart && $r['site_id'] !== null) {
            $sitePosts[(int) $r['site_id']] = ($sitePosts[(int) $r['site_id']] ?? 0) + 1;
        }
    }

    // Failed website publishes (successes are already counted above).
    $jobUserSql = $userId !== null ? ' AND user_id = :uid' : '';
    if (dashboardTableExists('publish_jobs')) {
        $stmt = $db->prepare(
            "SELECT UNIX_TIMESTAMP(created_at) AS ts, user_id, results FROM publish_jobs
             WHERE owner_id = :oid AND created_at >= FROM_UNIXTIME(:since) AND results IS NOT NULL" . $jobUserSql
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            [, $failed] = dashboardResultCounts($r['results']);
            $bump('failed', (int) $r['ts'], (int) $r['user_id'], $failed);
        }
    }

    // Facebook posts: each successfully scheduled page counts once.
    if (dashboardTableExists('schedule_jobs')) {
        $stmt = $db->prepare(
            "SELECT UNIX_TIMESTAMP(created_at) AS ts, user_id, results FROM schedule_jobs
             WHERE owner_id = :oid AND created_at >= FROM_UNIXTIME(:since) AND results IS NOT NULL" . $jobUserSql
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            [$ok, $failed] = dashboardResultCounts($r['results']);
            $bump('social', (int) $r['ts'], (int) $r['user_id'], $ok);
            $bump('failed', (int) $r['ts'], (int) $r['user_id'], $failed);
        }
    }

    $attempts = $period['web'] + $period['social'] + $period['failed'];
    $prevAttempts = $prev['web'] + $prev['social'] + $prev['failed'];

    return [
        'days'     => $days,
        'timezone' => $tz->getName(),
        'totals'   => [
            'web'               => $period['web'],
            'social'            => $period['social'],
            'failed'            => $period['failed'],
            'web_prev'          => $prev['web'],
            'social_prev'       => $prev['social'],
            'web_today'         => $today['web'],
            'social_today'      => $today['social'],
            // null when nothing was attempted (no rate to show)
            'success_rate'      => $attempts > 0 ? round(100 * ($attempts - $period['failed']) / $attempts, 1) : null,
            'success_rate_prev' => $prevAttempts > 0 ? round(100 * ($prevAttempts - $prev['failed']) / $prevAttempts, 1) : null,
        ],
        'series'     => array_values($series),
        'members'    => $members,
        'site_posts' => $sitePosts,
    ];
}


function dashboardTableExists(string $table): bool
{
    static $known = [];
    if (!array_key_exists($table, $known)) {
        $stmt = getDb()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $stmt->execute(['t' => $table]);
        $known[$table] = (bool) $stmt->fetchColumn();
    }
    return $known[$table];
}


/** Team table rows: every member with their period numbers and last activity (any time). */
function dashboardTeam(int $ownerId, array $memberStats): array
{
    $db = getDb();
    $last = [];
    $stmt = $db->prepare('SELECT posted_by, MAX(UNIX_TIMESTAMP(created_at)) AS ts FROM published_posts WHERE user_id = :oid AND posted_by IS NOT NULL GROUP BY posted_by');
    $stmt->execute(['oid' => $ownerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last[(int) $r['posted_by']] = (int) $r['ts'];
    }
    if (dashboardTableExists('schedule_jobs')) {
        $stmt = $db->prepare('SELECT user_id, MAX(UNIX_TIMESTAMP(created_at)) AS ts FROM schedule_jobs WHERE owner_id = :oid GROUP BY user_id');
        $stmt->execute(['oid' => $ownerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $last[(int) $r['user_id']] = max($last[(int) $r['user_id']] ?? 0, (int) $r['ts']);
        }
    }

    $rows = [];
    foreach (getTeamMembers($ownerId) as $u) {
        $id = (int) $u['id'];
        $s = $memberStats[$id] ?? ['web' => 0, 'social' => 0, 'failed' => 0];
        $attempts = $s['web'] + $s['social'] + $s['failed'];
        $rows[] = [
            'id'           => $id,
            'name'         => (string) $u['name'],
            'role'         => (string) $u['role'],
            'web'          => $s['web'],
            'social'       => $s['social'],
            'failed'       => $s['failed'],
            'success_rate' => $attempts > 0 ? round(100 * ($attempts - $s['failed']) / $attempts, 1) : null,
            'last_active'  => isset($last[$id]) && $last[$id] > 0 ? date('c', $last[$id]) : null,
        ];
    }
    usort($rows, static fn ($a, $b) => ($b['web'] + $b['social']) <=> ($a['web'] + $a['social']) ?: strcmp($a['name'], $b['name']));
    return $rows;
}


/** Website table rows: posts in the period and the last post ever, per connected site. */
function dashboardSites(int $ownerId, array $sitePosts): array
{
    $stmt = getDb()->prepare('SELECT site_id, MAX(UNIX_TIMESTAMP(created_at)) AS ts FROM published_posts WHERE user_id = :oid AND site_id IS NOT NULL GROUP BY site_id');
    $stmt->execute(['oid' => $ownerId]);
    $last = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $last[(int) $r['site_id']] = (int) $r['ts'];
    }
    $rows = [];
    foreach (getWordPressSitesForUser($ownerId) as $s) {
        $id = (int) $s['id'];
        $rows[] = [
            'id'        => $id,
            'name'      => (string) $s['site_name'],
            'url'       => (string) $s['site_url'],
            'posts'     => $sitePosts[$id] ?? 0,
            'last_post' => isset($last[$id]) ? date('c', $last[$id]) : null,
        ];
    }
    usort($rows, static fn ($a, $b) => $b['posts'] <=> $a['posts'] ?: strcmp($a['name'], $b['name']));
    return $rows;
}
