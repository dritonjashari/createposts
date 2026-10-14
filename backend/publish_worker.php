<?php
// ---------------------------------------------------------------------------
// Background worker for "Publish to Websites" jobs.
//
// Run it two ways (both safe to run at once — jobs are claimed atomically):
//   1. Cron (reliable):   * * * * * /usr/bin/php /path/to/publish_worker.php
//   2. HTTP kick (instant): publish.php pings this right after queuing a job,
//      so work starts without waiting for the next cron minute.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/functions.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Over HTTP the shared secret is required (see PUBLISH_WORKER_TOKEN).
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!hash_equals(PUBLISH_WORKER_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    // Return immediately to the caller, then keep processing in the background.
    ignore_user_abort(true);
    @set_time_limit(0);
    if (function_exists('fastcgi_finish_request')) {
        echo 'ok';
        @fastcgi_finish_request();
    }
}

@set_time_limit(0);
ignore_user_abort(true);

// Recover any job left stuck at "processing" by a killed worker process
// before picking up new work (see reapStalePublishJobs()).
try {
    reapStalePublishJobs();
} catch (Throwable $e) {
    // Best-effort — a failure here shouldn't block picking up new jobs below.
}

// Pick up queued jobs. A single job publishes to all of its sites (which can be
// slow), so we stop claiming NEW jobs after a short budget to keep one run
// bounded; a job already in progress is never re-claimed.
$deadline     = time() + 55;
$processedAny = false;

while (time() < $deadline) {
    $job = claimNextPublishJob();
    if (!$job) {
        break;
    }
    $processedAny = true;
    try {
        runPublishJob($job);
    } catch (Throwable $e) {
        // Don't leave a failed job stuck as "processing".
        try {
            $errResults = ['_error' => $e->getMessage()];
            getDb()->prepare("UPDATE publish_jobs SET state = 'error', finished_at = NOW(), results = :r WHERE id = :id")
                   ->execute(['r' => json_encode($errResults), 'id' => $job['id']]);
            @sendPublishFailureAlert((int) $job['owner_id'], $job, $errResults);
        } catch (Throwable $ignore) {
        }
    }
}

if ($isCli) {
    fwrite(STDOUT, $processedAny ? "Processed queued publish jobs.\n" : "No queued publish jobs.\n");
}
