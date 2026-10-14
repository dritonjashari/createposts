<?php


// ===========================================================================
// Schedule Posts (Publer) background jobs — same idea as the publish_jobs
// system above, but for scheduling Facebook posts page-by-page via Publer.
// ===========================================================================

function ensureScheduleJobsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS schedule_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            owner_id INT NOT NULL,
            workspace_id VARCHAR(64) NOT NULL,
            text MEDIUMTEXT NOT NULL,
            comment TEXT NOT NULL,
            use_mapped_post TINYINT(1) NOT NULL DEFAULT 0,
            paraphrase TINYINT(1) NOT NULL DEFAULT 0,
            paraphrase_comment TINYINT(1) NOT NULL DEFAULT 0,
            paraphrase_comment2 TINYINT(1) NOT NULL DEFAULT 0,
            paraphrase_tone VARCHAR(32) NOT NULL DEFAULT 'neutral',
            paraphrase_custom_prompt TEXT NULL,
            post_now TINYINT(1) NOT NULL DEFAULT 0,
            use_timeslot TINYINT(1) NOT NULL DEFAULT 0,
            base_ts INT NOT NULL DEFAULT 0,
            gap_seconds INT NOT NULL DEFAULT 0,
            timeslot_cursor_key VARCHAR(64) NULL,
            media_path VARCHAR(512) NULL,
            media_mime VARCHAR(100) NULL,
            media_name VARCHAR(255) NULL,
            account_ids TEXT NOT NULL,
            comment_delay_minutes INT NOT NULL DEFAULT 0,
            comment2 TEXT NULL,
            comment2_delay_minutes INT NOT NULL DEFAULT 0,
            total INT NOT NULL DEFAULT 0,
            done INT NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'queued',
            results MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            CONSTRAINT fk_sj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS comment_delay_minutes INT NOT NULL DEFAULT 0");
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS comment2 TEXT NULL");
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS comment2_delay_minutes INT NOT NULL DEFAULT 0");
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS paraphrase_comment2 TINYINT(1) NOT NULL DEFAULT 0");
    // Mapped-post links must be resolved once, at submit time, and frozen here
    // — see the comment above the mapped_links handling in runScheduleJob().
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS mapped_links TEXT NULL");
    // Publer "delete" callback age in hours, frozen at submit time (0 = off).
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS auto_delete_hours INT NOT NULL DEFAULT 0");
    // 1 = give every page its own pixel-marked copy of a photo; 0 = one shared upload.
    getDb()->exec("ALTER TABLE schedule_jobs ADD COLUMN IF NOT EXISTS add_pixels TINYINT(1) NOT NULL DEFAULT 1");
    $ensured = true;
}


/** Queue a schedule job; returns the new job id. $d holds the parsed form/request data. */
function createScheduleJob(int $ownerId, int $userId, array $d): int
{
    ensureScheduleJobsTable();
    $stmt = getDb()->prepare(
        'INSERT INTO schedule_jobs
            (user_id, owner_id, workspace_id, text, comment, use_mapped_post, paraphrase, paraphrase_comment,
             paraphrase_comment2, paraphrase_tone, paraphrase_custom_prompt, post_now, use_timeslot, base_ts, gap_seconds,
             timeslot_cursor_key, media_path, media_mime, media_name, account_ids, comment_delay_minutes,
             comment2, comment2_delay_minutes, mapped_links, auto_delete_hours, add_pixels, total, state)
         VALUES
            (:uid, :oid, :ws, :text, :comment, :ump, :para, :parac,
             :parac2, :tone, :custom, :now, :ts, :base, :gap,
             :tck, :mp, :mm, :mn, :aids, :cdelay, :comment2, :cdelay2, :mlinks, :adel, :pixels, :total, "queued")'
    );
    $stmt->execute([
        'uid'    => $userId,
        'oid'    => $ownerId,
        'ws'     => $d['workspace_id'],
        'text'   => $d['text'],
        'comment' => $d['comment'],
        'ump'    => !empty($d['use_mapped_post']) ? 1 : 0,
        'para'   => !empty($d['paraphrase']) ? 1 : 0,
        'parac'  => !empty($d['paraphrase_comment']) ? 1 : 0,
        'parac2' => !empty($d['paraphrase_comment2']) ? 1 : 0,
        'tone'   => $d['paraphrase_tone'],
        'custom' => $d['paraphrase_custom_prompt'] ?? '',
        'now'    => !empty($d['post_now']) ? 1 : 0,
        'ts'     => !empty($d['use_timeslot']) ? 1 : 0,
        'base'   => (int) $d['base_ts'],
        'gap'    => (int) $d['gap_seconds'],
        'tck'    => $d['timeslot_cursor_key'] ?? null,
        'mp'     => $d['media_path'] ?? null,
        'mm'     => $d['media_mime'] ?? null,
        'mn'     => $d['media_name'] ?? null,
        'aids'   => json_encode(array_values($d['account_ids'])),
        'cdelay' => max(0, (int) ($d['comment_delay_minutes'] ?? 0)),
        'comment2' => $d['comment2'] ?? '',
        'cdelay2'  => max(0, (int) ($d['comment2_delay_minutes'] ?? 0)),
        // Snapshot of accountId => mapped WordPress post link, resolved once at
        // submit time (see publer_scheduleAction()) so a delayed worker run
        // never picks up a link that's since been superseded by a newer
        // publish. See the comment in runScheduleJob() for the full story.
        'mlinks' => json_encode($d['mapped_links'] ?? []),
        'adel'   => max(0, (int) ($d['auto_delete_hours'] ?? 0)),
        'pixels' => !array_key_exists('add_pixels', $d) || !empty($d['add_pixels']) ? 1 : 0,
        'total'  => count($d['account_ids']),
    ]);
    return (int) getDb()->lastInsertId();
}


/** Fetch a job for display (scoped to the account owner). */
function getScheduleJob(int $id, int $ownerId): ?array
{
    ensureScheduleJobsTable();
    $stmt = getDb()->prepare('SELECT * FROM schedule_jobs WHERE id = :id AND owner_id = :oid');
    $stmt->execute(['id' => $id, 'oid' => $ownerId]);
    return $stmt->fetch() ?: null;
}


/**
 * A page of this owner's schedule jobs, most recent first — used by the
 * unified Jobs Status page. Mirrors getPublishJobsForOwner().
 */
function getScheduleJobsForOwner(int $ownerId, int $limit, int $offset, ?int $postedBy = null): array
{
    ensureScheduleJobsTable();
    $sql = 'SELECT sj.id, sj.text, sj.total, sj.done, sj.state, sj.results, sj.created_at, sj.finished_at,
                   sj.user_id, u.name AS posted_by_name
            FROM schedule_jobs sj
            LEFT JOIN users u ON u.id = sj.user_id
            WHERE sj.owner_id = :oid';
    $params = ['oid' => $ownerId];
    if ($postedBy !== null) {
        $sql .= ' AND sj.user_id = :uid';
        $params['uid'] = $postedBy;
    }
    $sql .= ' ORDER BY sj.created_at DESC LIMIT :lim OFFSET :off';

    $stmt = getDb()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $jobs = [];
    foreach ($stmt->fetchAll() as $job) {
        $results = json_decode((string) ($job['results'] ?? ''), true);
        $job['results'] = is_array($results) ? $results : [];
        $failCount = 0;
        foreach ($job['results'] as $r) {
            if (empty($r['success']) && empty($r['retried'])) {
                $failCount++;
            }
        }
        $job['fail_count'] = $failCount;
        $jobs[] = $job;
    }
    return $jobs;
}


/** Total count for getScheduleJobsForOwner()'s pagination. */
function getScheduleJobsCountForOwner(int $ownerId, ?int $postedBy = null): int
{
    ensureScheduleJobsTable();
    $sql = 'SELECT COUNT(*) FROM schedule_jobs WHERE owner_id = :oid';
    $params = ['oid' => $ownerId];
    if ($postedBy !== null) {
        $sql .= ' AND user_id = :uid';
        $params['uid'] = $postedBy;
    }
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}


/** Recently finished jobs with at least one page that failed — dashboard "needs attention". */
function getScheduleJobsNeedingAttention(int $ownerId, int $limit = 10, ?int $userId = null): array
{
    ensureScheduleJobsTable();
    // $userId: only that member's own jobs (an editor's dashboard).
    $stmt = getDb()->prepare(
        "SELECT id, text, total, done, results, finished_at FROM schedule_jobs
         WHERE owner_id = :oid AND state IN ('done', 'error') AND results IS NOT NULL
           AND finished_at >= (NOW() - INTERVAL 7 DAY)" . ($userId !== null ? ' AND user_id = :uid' : '') . "
         ORDER BY finished_at DESC
         LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute(['oid' => $ownerId] + ($userId !== null ? ['uid' => $userId] : []));

    $out = [];
    foreach ($stmt->fetchAll() as $job) {
        $results = json_decode((string) $job['results'], true);
        if (!is_array($results)) {
            continue;
        }
        $failCount = 0;
        foreach ($results as $r) {
            if (empty($r['success']) && empty($r['retried'])) {
                $failCount++;
            }
        }
        if ($failCount > 0) {
            $job['fail_count'] = $failCount;
            $out[] = $job;
        }
    }
    return $out;
}


/**
 * Same idea as reapStalePublishJobs() — a killed worker process (hard
 * server-side timeout, outside this app's control) can leave a schedule job
 * stuck at "processing" forever. Recover it: any page with no final result
 * is marked failed so the job closes out as 'error' instead of vanishing.
 */
function reapStaleScheduleJobs(int $staleMinutes = 15): void
{
    ensureScheduleJobsTable();
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT * FROM schedule_jobs
         WHERE state = 'processing' AND started_at IS NOT NULL AND started_at < (NOW() - INTERVAL :m MINUTE)"
    );
    $stmt->bindValue(':m', $staleMinutes, PDO::PARAM_INT);
    $stmt->execute();

    foreach ($stmt->fetchAll() as $job) {
        $accountIds = json_decode((string) $job['account_ids'], true);
        $accountIds = is_array($accountIds) ? $accountIds : [];
        $results = json_decode((string) ($job['results'] ?? ''), true);
        $results = is_array($results) ? $results : [];

        foreach ($accountIds as $aid) {
            if (!empty($results[$aid]['success'])) {
                continue; // leave completed pages alone
            }
            $results[$aid] = [
                'name'    => $results[$aid]['name'] ?? ('Page #' . $aid),
                'success' => false,
                'message' => 'Timed out — the server stopped responding partway through. Please try scheduling this post again.',
            ];
        }

        $db->prepare("UPDATE schedule_jobs SET state = 'error', finished_at = NOW(), results = :r WHERE id = :id")
           ->execute(['r' => json_encode($results), 'id' => $job['id']]);
    }
}


/** Atomically claim the next queued job (marks it processing). Null when none. */
function claimNextScheduleJob(): ?array
{
    ensureScheduleJobsTable();
    $db = getDb();
    $db->beginTransaction();
    try {
        $job = $db->query("SELECT * FROM schedule_jobs WHERE state = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) {
            $db->commit();
            return null;
        }
        $db->prepare("UPDATE schedule_jobs SET state = 'processing', started_at = NOW() WHERE id = :id")
           ->execute(['id' => $job['id']]);
        $db->commit();
        $job['state'] = 'processing';
        return $job;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return null;
    }
}


/**
 * Process a claimed schedule job one Facebook page at a time, persisting
 * done/results after each page so the status page shows real progress.
 * Mirrors the per-page logic in publer.php's synchronous fallback.
 */
function runScheduleJob(array $job): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    // Background work: if Publer's shared rate limit is hit, wait for the
    // window to reset and continue rather than failing pages.
    $GLOBALS['publer_wait_on_rate_limit'] = true;

    $jobId       = (int) $job['id'];
    $ownerId     = (int) $job['owner_id'];
    $workspaceId = (string) $job['workspace_id'];
    $apiKey      = getPublerApiKey($ownerId);
    $accountIds  = (array) (json_decode((string) $job['account_ids'], true) ?: []);
    $postNow     = !empty($job['post_now']);
    $useTimeslot = !empty($job['use_timeslot']);
    $baseTs      = (int) $job['base_ts'];
    $gapSecs     = (int) $job['gap_seconds'];
    $paraphrase  = !empty($job['paraphrase']);
    $paraphraseComment = !empty($job['paraphrase_comment']);
    $paraphraseComment2 = !empty($job['paraphrase_comment2']);
    $tone          = (string) $job['paraphrase_tone'];
    $customPrompt  = (string) ($job['paraphrase_custom_prompt'] ?? '');
    $postText      = (string) $job['text'];
    $comment       = (string) $job['comment'];
    $useMappedPost = !empty($job['use_mapped_post']);
    $commentDelayMinutes = (int) ($job['comment_delay_minutes'] ?? 0);
    $comment2      = (string) ($job['comment2'] ?? '');
    $comment2DelayMinutes = (int) ($job['comment2_delay_minutes'] ?? 0);
    $mappedLinkTarget = getSetting($ownerId, 'mapped_link_target', 'comment1');
    $autoDelete       = publerAutoDeleteCallback((int) ($job['auto_delete_hours'] ?? 0));
    $addPixels        = !array_key_exists('add_pixels', $job) || !empty($job['add_pixels']);

    $facebookPages = fetchPublerFacebookPages($ownerId, $workspaceId);
    $pageNames = [];
    foreach ($facebookPages as $p) {
        $pageNames[$p['id']] = $p['name'];
    }
    // Mapped-post links are resolved ONCE at submit time (publer_scheduleAction)
    // and frozen into this column — never re-resolved here. A queued job can
    // sit for a while before a worker picks it up; re-querying "the latest
    // published post" at run time would let a link scheduled against Article A
    // silently get swapped for Article B if something newer got published
    // while this job was waiting in the queue.
    $mappedLinks = json_decode((string) ($job['mapped_links'] ?? ''), true);
    $mappedLinks = is_array($mappedLinks) ? $mappedLinks : [];

    $storedMedia = null;
    if (!empty($job['media_path']) && is_file($job['media_path'])) {
        $storedMedia = ['path' => $job['media_path'], 'mime' => (string) $job['media_mime'], 'name' => (string) $job['media_name']];
    }
    $isVideo = $storedMedia !== null && str_starts_with($storedMedia['mime'], 'video/');
    $isPhoto = $storedMedia !== null && str_starts_with($storedMedia['mime'], 'image/');

    // A video is uploaded once and shared by every page. A photo, when
    // "add pixels" is on, gets its own freshly-marked, separately-uploaded
    // copy PER PAGE below (see the loop), so every Facebook page ends up with
    // a differently-hashed image; with it off, one upload is shared by all.
    $networkBase = ['type' => 'status'];
    $sharedUploadError = '';
    if ($isPhoto && !$addPixels) {
        $media = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $sharedUploadError, false);
        $networkBase['type'] = 'photo';
        if ($media !== null) {
            $networkBase['media'] = [['id' => (string) $media['id'], 'type' => 'image']];
        }
    } elseif ($isVideo) {
        $media = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $sharedUploadError, false);
        if ($media !== null) {
            $mediaObj = ['id' => (string) $media['id']];
            if (!empty($media['thumbnails'])) {
                $mediaObj['thumbnails']        = $media['thumbnails'];
                $mediaObj['default_thumbnail'] = 0;
            }
            $networkBase['type']    = 'video';
            $networkBase['media']   = [$mediaObj];
            $networkBase['details'] = ['type' => 'reel'];
        }
    } elseif ($isPhoto) {
        $networkBase['type'] = 'photo';
    }

    $pageCountTotal = count($accountIds);
    $results = [];
    $done    = 0;
    $endpoint    = $postNow ? 'posts/schedule/publish' : 'posts/schedule';

    foreach ($accountIds as $i => $aid) {
        $name = $pageNames[$aid] ?? ('Page ' . $aid);

        if ($sharedUploadError !== '') {
            $results[$aid] = ['name' => $name, 'success' => false, 'message' => $sharedUploadError];
            $done++;
            getDb()->prepare('UPDATE schedule_jobs SET done = :d, results = :r WHERE id = :id')
                   ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $jobId]);
            continue;
        }

        $pageFailed = false;
        $pageText   = $postText;
        if ($paraphrase) {
            $rew = paraphraseWithOpenAI($ownerId, $postText, $tone, $name, $i, $pageCountTotal, $customPrompt);
            if (!empty($rew['success']) && !empty($rew['text'])) {
                $pageText = $rew['text'];
            } else {
                $pageFailed = true;
            }
        }

        $pageCommentText = $comment;
        if ($paraphraseComment && $comment !== '') {
            $rewC = paraphraseWithOpenAI($ownerId, $comment, $tone, $name, $i, $pageCountTotal, $customPrompt);
            if (!empty($rewC['success']) && !empty($rewC['text'])) {
                $pageCommentText = $rewC['text'];
            } else {
                $pageFailed = true;
            }
        }

        $pageComment2Text = $comment2;
        if ($paraphraseComment2 && $comment2 !== '') {
            $rewC2 = paraphraseWithOpenAI($ownerId, $comment2, $tone, $name, $i, $pageCountTotal, $customPrompt);
            if (!empty($rewC2['success']) && !empty($rewC2['text'])) {
                $pageComment2Text = $rewC2['text'];
            } else {
                $pageFailed = true;
            }
        }

        $mappingMissing = false;
        $mappedLink     = null;
        if ($useMappedPost && array_key_exists($aid, $mappedLinks)) {
            $mappedLink     = $mappedLinks[$aid];
            $mappingMissing = ($mappedLink === null);
        }
        // Which comment gets the mapped-post link is admin-configurable
        // (System page); the other comment is left as its own plain text.
        $pageComment  = ($useMappedPost && $mappedLinkTarget !== 'comment2')
            ? buildFirstComment($pageCommentText, $mappedLink) : $pageCommentText;
        $pageComment2 = ($useMappedPost && $mappedLinkTarget === 'comment2')
            ? buildFirstComment($pageComment2Text, $mappedLink) : $pageComment2Text;

        $account = ['id' => $aid];
        if (!$postNow) {
            $account['scheduled_at'] = date('c', $baseTs + ($i * $gapSecs));
        }
        // There's no "delay" field on a comment (that only exists for
        // AutoShare) — timing is an "age" condition instead (Publer's Post
        // Callbacks API): the comment posts once the post is at least this
        // many minutes old, instead of at the same instant it goes live.
        $commentEntries = [];
        if ($pageComment !== '') {
            $entry = ['text' => $pageComment];
            if ($commentDelayMinutes > 0) {
                $entry['conditions'] = [
                    'relation' => 'AND',
                    'clauses'  => ['age' => ['duration' => $commentDelayMinutes, 'unit' => 'Minute']],
                ];
            }
            $commentEntries[] = $entry;
        }
        if ($pageComment2 !== '') {
            $entry2 = ['text' => $pageComment2];
            if ($comment2DelayMinutes > 0) {
                $entry2['conditions'] = [
                    'relation' => 'AND',
                    'clauses'  => ['age' => ['duration' => $comment2DelayMinutes, 'unit' => 'Minute']],
                ];
            }
            $commentEntries[] = $entry2;
        }
        if (!empty($commentEntries)) {
            $account['comments'] = $commentEntries;
        }
        if ($autoDelete !== null) {
            $account['delete'] = $autoDelete;
        }

        $net = $networkBase;
        $net['text'] = $pageText;
        $pageUploadError = '';
        if ($isPhoto && $addPixels) {
            $up = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $pageUploadError, true, 25);
            if ($up !== null) {
                $net['media'] = [['id' => (string) $up['id'], 'type' => 'image']];
            } else {
                $pageFailed = true;
            }
        }

        if ($pageUploadError !== '') {
            $results[$aid] = ['name' => $name, 'success' => false, 'message' => $pageUploadError];
        } else {
            $payload = ['bulk' => ['state' => 'scheduled', 'posts' => [[
                'networks' => ['facebook' => $net],
                'accounts' => [$account],
            ]]]];

            $res = publerRequest('POST', $endpoint, $apiKey, $workspaceId, $payload);
            if ($res['error'] !== '') {
                $results[$aid] = ['name' => $name, 'success' => false, 'message' => 'Connection failed: ' . $res['error']];
            } elseif ($res['code'] === 200 || $res['code'] === 201) {
                $failMsgs    = [];
                $jobIdRemote = $res['data']['job_id'] ?? '';
                if ($jobIdRemote !== '') {
                    $jobStatus   = publerRequest('GET', 'job_status/' . $jobIdRemote, $apiKey, $workspaceId);
                    $jobFailures = $jobStatus['data']['payload']['failures'] ?? null;
                    if (!empty($jobFailures) && is_array($jobFailures)) {
                        foreach ($jobFailures as $postFails) {
                            foreach ((array) $postFails as $f) {
                                $failMsgs[] = ($f['message'] ?? 'failed');
                            }
                        }
                    }
                }
                if (!empty($failMsgs)) {
                    $results[$aid] = ['name' => $name, 'success' => false, 'message' => implode(' | ', $failMsgs)];
                } else {
                    $msg = $pageFailed ? 'Scheduled (kept original text — paraphrase failed).' : 'Scheduled.';
                    if ($mappingMissing) {
                        $msg .= ' No app-published post found to link.';
                    }
                    $results[$aid] = ['name' => $name, 'success' => true, 'message' => $msg];
                }
            } else {
                $detail = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
                $results[$aid] = ['name' => $name, 'success' => false, 'message' => 'HTTP ' . $res['code'] . ': ' . $detail];
            }
        }

        $done++;
        getDb()->prepare('UPDATE schedule_jobs SET done = :d, results = :r WHERE id = :id')
               ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $jobId]);
    }

    // The timeslot cursor is reserved eagerly when the job is created (right
    // when $baseTs is decided), not here — see createScheduleJob()'s callers.
    // Advancing it only after this job finishes would let a second "Use
    // Timeslot" submission made before this job runs compute the same "free"
    // slot and double-book it.

    // Only clean up the persisted media once every page succeeded — a failed
    // page's retry (see retryScheduleJobFailures()) still needs the file.
    $anyFailed = false;
    foreach ($results as $r) {
        if (empty($r['success'])) { $anyFailed = true; break; }
    }
    if (!$anyFailed && $storedMedia !== null && is_file($storedMedia['path'])) {
        @unlink($storedMedia['path']);
    }

    getDb()->prepare("UPDATE schedule_jobs SET state = 'done', done = :d, results = :r, finished_at = NOW() WHERE id = :id")
           ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $jobId]);
    publerCacheBump($workspaceId); // the queue changed — refresh cached reads
    unset($GLOBALS['publer_wait_on_rate_limit']);
}


/**
 * Re-attempt only the pages that failed in a finished schedule job — reusing
 * the same text/comments/settings and the same schedule time/media. Pages
 * that already succeeded are left untouched. Returns the new job id, or an
 * error message if there's nothing to retry. Mirrors retryPublishJobFailures().
 */
function retryScheduleJobFailures(int $jobId, int $ownerId, int $userId): array
{
    $job = getScheduleJob($jobId, $ownerId);
    if (!$job) {
        return ['success' => false, 'message' => 'Job not found.'];
    }
    if (!in_array($job['state'], ['done', 'error'], true)) {
        return ['success' => false, 'message' => 'This job is still running — wait for it to finish first.'];
    }

    $results = json_decode((string) ($job['results'] ?? '[]'), true);
    $results = is_array($results) ? $results : [];

    $failedAccountIds = [];
    foreach ($results as $aid => $r) {
        if (!empty($r['success']) || !empty($r['retried'])) {
            continue; // already scheduled, or already handed off to a newer retry job
        }
        $failedAccountIds[] = (string) $aid;
    }

    if (empty($failedAccountIds)) {
        return ['success' => false, 'message' => 'No failed pages to retry.'];
    }
    // Never quietly post text-only when the original had a photo/video.
    if (!empty($job['media_path']) && !is_file($job['media_path'])) {
        return ['success' => false, 'message' => 'The image/video of this post is no longer on the server, so it cannot be retried as-is — schedule it again (or use Repost in the Post Queue).'];
    }

    // Carry over the ORIGINAL frozen link snapshot for just these accounts —
    // a retry re-runs the same submission, not a fresh "use whatever's latest
    // now" lookup.
    $mappedLinks = json_decode((string) ($job['mapped_links'] ?? ''), true);
    $mappedLinks = is_array($mappedLinks) ? $mappedLinks : [];
    $retryMappedLinks = array_intersect_key($mappedLinks, array_flip($failedAccountIds));

    $newJobId = createScheduleJob($ownerId, $userId, [
        'workspace_id'             => (string) $job['workspace_id'],
        'text'                     => (string) $job['text'],
        'comment'                  => (string) $job['comment'],
        'use_mapped_post'          => (bool) $job['use_mapped_post'],
        'paraphrase'               => (bool) $job['paraphrase'],
        'paraphrase_comment'       => (bool) $job['paraphrase_comment'],
        'paraphrase_comment2'      => (bool) $job['paraphrase_comment2'],
        'paraphrase_tone'          => (string) $job['paraphrase_tone'],
        'paraphrase_custom_prompt' => (string) ($job['paraphrase_custom_prompt'] ?? ''),
        'post_now'                 => (bool) $job['post_now'],
        'use_timeslot'             => (bool) $job['use_timeslot'],
        'base_ts'                  => (int) $job['base_ts'],
        'gap_seconds'              => (int) $job['gap_seconds'],
        // The original temp upload is long gone by now, but the *persisted*
        // copy runScheduleJob() kept (because this job still had a failure)
        // is reused here — still cleaned up once the retry fully succeeds.
        'media_path'               => $job['media_path'] ?: null,
        'media_mime'               => $job['media_mime'] ?? null,
        'media_name'               => $job['media_name'] ?? null,
        'account_ids'              => $failedAccountIds,
        'comment_delay_minutes'    => (int) $job['comment_delay_minutes'],
        'comment2'                 => (string) ($job['comment2'] ?? ''),
        'comment2_delay_minutes'   => (int) $job['comment2_delay_minutes'],
        'mapped_links'             => $retryMappedLinks,
        'auto_delete_hours'        => (int) ($job['auto_delete_hours'] ?? 0),
        'add_pixels'               => !array_key_exists('add_pixels', $job) || !empty($job['add_pixels']),
    ]);

    // Mark the failed pages on the ORIGINAL job as handed off to this retry —
    // otherwise getScheduleJobsNeedingAttention() keeps surfacing the same old
    // failure forever, making "Retry failed pages" look like a no-op even
    // though a new job is quietly running.
    foreach ($failedAccountIds as $aid) {
        $results[$aid]['retried']          = true;
        $results[$aid]['retried_as_job_id'] = $newJobId;
    }
    getDb()->prepare('UPDATE schedule_jobs SET results = :r WHERE id = :id')
           ->execute(['r' => json_encode($results), 'id' => $jobId]);

    return ['success' => true, 'job_id' => $newJobId];
}


function triggerScheduleWorkerAsync(): void
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    $url = $scheme . '://' . $host . $dir . '/schedule_worker.php?token=' . rawurlencode(SCHEDULE_WORKER_TOKEN);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);
}
