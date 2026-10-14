<?php
// Jobs Status — unified list over publish_jobs + schedule_jobs, with retry.

function jobs_listAction(): void
{
    requireLoginApi();
    kickStalledWorkers();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $perPage = 10;
    $page    = max(1, (int) ($_GET['page'] ?? 1));

    // Admins see every job under their account; editors only their own.
    $postedByFilter = isAdmin() ? null : $userId;

    $total      = getAllJobsCountForOwner($ownerId, $postedByFilter);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    jsonResponse([
        'jobs'        => getAllJobsForOwner($ownerId, $perPage, ($page - 1) * $perPage, $postedByFilter),
        'page'        => $page,
        'total_pages' => $totalPages,
        'total'       => $total,
        'is_admin'    => isAdmin(),
    ]);
}

function jobs_retryAction(string $jobType, string $jobId): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);

    if (!in_array($jobType, ['publish', 'schedule'], true)) {
        jsonError(404, 'Unknown job type.');
    }
    // Editors only see their own jobs in the list; enforce the same here.
    _publish_assertCanRetry(
        $jobType === 'publish' ? getPublishJob((int) $jobId, $ownerId) : getScheduleJob((int) $jobId, $ownerId),
        $userId
    );

    $result = retryJobFailures($jobType, (int) $jobId, $ownerId, $userId);
    if (empty($result['success'])) {
        jsonError(422, $result['message'] ?? 'Could not retry this job.');
    }

    if ($jobType === 'publish') {
        triggerPublishWorkerAsync();
    } else {
        triggerScheduleWorkerAsync();
    }

    jsonResponse(['job_id' => $result['job_id']], 202);
}
