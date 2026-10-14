<?php


// ===========================================================================
// Jobs Status — unified read-only view over publish_jobs + schedule_jobs for
// the "Jobs Status" page. Retry logic still lives in each job type's own lib
// file (publish_jobs.php / schedule_jobs.php); this just merges the two
// tables for a single combined, paginated feed.
// ===========================================================================

/**
 * A page of this owner's jobs (publish + schedule combined), most recent
 * first. Each row carries a `job_type` of 'publish' or 'schedule' so the
 * frontend knows which retry endpoint / label to use.
 */
function getAllJobsForOwner(int $ownerId, int $limit, int $offset, ?int $postedBy = null): array
{
    ensurePublishJobsTable();
    ensureScheduleJobsTable();

    $params = ['oid1' => $ownerId, 'oid2' => $ownerId];
    $userFilter1 = '';
    $userFilter2 = '';
    if ($postedBy !== null) {
        $userFilter1 = ' AND pj.user_id = :uid1';
        $userFilter2 = ' AND sj.user_id = :uid2';
        $params['uid1'] = $postedBy;
        $params['uid2'] = $postedBy;
    }

    $sql = "SELECT * FROM (
                SELECT 'publish' AS job_type, pj.id, pj.title AS label, pj.total, pj.done, pj.state, pj.results,
                       pj.created_at, pj.finished_at, pj.user_id, u1.name AS posted_by_name
                FROM publish_jobs pj
                LEFT JOIN users u1 ON u1.id = pj.user_id
                WHERE pj.owner_id = :oid1 $userFilter1
                UNION ALL
                SELECT 'schedule' AS job_type, sj.id, sj.text AS label, sj.total, sj.done, sj.state, sj.results,
                       sj.created_at, sj.finished_at, sj.user_id, u2.name AS posted_by_name
                FROM schedule_jobs sj
                LEFT JOIN users u2 ON u2.id = sj.user_id
                WHERE sj.owner_id = :oid2 $userFilter2
            ) combined
            ORDER BY created_at DESC
            LIMIT :lim OFFSET :off";

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
            if (empty($r['success']) && empty($r['pending']) && empty($r['retried'])) {
                $failCount++;
            }
        }
        $job['fail_count'] = $failCount;
        $jobs[] = $job;
    }
    return $jobs;
}


/** Total count for getAllJobsForOwner()'s pagination. */
function getAllJobsCountForOwner(int $ownerId, ?int $postedBy = null): int
{
    return getPublishJobsCountForOwner($ownerId, $postedBy) + getScheduleJobsCountForOwner($ownerId, $postedBy);
}


/** Dispatch a retry to the right job-type's retry function. */
function retryJobFailures(string $jobType, int $jobId, int $ownerId, int $userId): array
{
    if ($jobType === 'publish') {
        return retryPublishJobFailures($jobId, $ownerId, $userId);
    }
    if ($jobType === 'schedule') {
        return retryScheduleJobFailures($jobId, $ownerId, $userId);
    }
    return ['success' => false, 'message' => 'Unknown job type.'];
}


/**
 * Cron-free backstop for the background workers. A job normally starts right
 * away (the request that queues it kicks its worker over HTTP), and cron, when
 * set up, catches anything that kick missed. Without cron, a job could sit
 * 'queued' (the kick failed, or a worker used up its claim budget with more
 * jobs waiting) or stuck at 'processing' (worker killed) until someone queued
 * another job. So the endpoints the UI polls call this: if a job has waited
 * too long, kick that worker again. Throttled to one check per worker type
 * per 30s across all requests; an extra worker run is harmless anyway (jobs
 * are claimed atomically).
 */
function kickStalledWorkers(): void
{
    $workers = [
        'publish'  => ['publish_jobs',  'ensurePublishJobsTable',  'triggerPublishWorkerAsync'],
        'schedule' => ['schedule_jobs', 'ensureScheduleJobsTable', 'triggerScheduleWorkerAsync'],
    ];
    foreach ($workers as $type => [$table, $ensureTable, $trigger]) {
        if (publerCacheGet('worker-kick:' . $type, 30) !== null) {
            continue;
        }
        publerCacheSet('worker-kick:' . $type, time());
        try {
            $ensureTable();
            $stalled = getDb()->query(
                "SELECT 1 FROM {$table}
                 WHERE (state = 'queued' AND created_at < NOW() - INTERVAL 15 SECOND)
                    OR (state = 'processing' AND started_at < NOW() - INTERVAL 15 MINUTE)
                 LIMIT 1"
            )->fetchColumn();
            if ($stalled) {
                $trigger();
            }
        } catch (Throwable $e) {
            // Best-effort — never fail the polling request over this.
        }
    }
}
