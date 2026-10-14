<?php
// Dashboard. One role-aware endpoint for the statistics (admins see the
// whole team, editors only their own work) plus a separate paginated list of
// recent website posts, so paging that list never recomputes the numbers.

function dashboard_getAction(): void
{
    requireLoginApi();
    kickStalledWorkers();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $days    = ((int) ($_GET['days'] ?? 7)) === 30 ? 30 : 7;
    $isAdmin = isAdmin();
    $stats   = dashboardStats($ownerId, $isAdmin ? null : $userId, $days, getAppTimezone($userId));

    $base = [
        'role'        => $isAdmin ? 'admin' : 'editor',
        'user'        => currentUser(),
        'days'        => $stats['days'],
        'timezone'    => $stats['timezone'],
        'totals'      => $stats['totals'],
        'series'      => $stats['series'],
        // Publer's own queue size (cached 60 s server-side, shared by the team).
        'queue_count' => getPublerScheduledCount($userId),
    ];

    if ($isAdmin) {
        $openTasks = array_values(array_filter(getTasksForAdmin($userId), static fn ($t) => $t['status'] !== 'done'));
        jsonResponse($base + [
            'team'            => dashboardTeam($ownerId, $stats['members']),
            'sites'           => dashboardSites($ownerId, $stats['site_posts']),
            'open_tasks'      => array_slice($openTasks, 0, 10),
            'open_task_count' => count($openTasks),
            'finished_tasks'  => getRecentlyFinishedTasks($userId, 6),
            'pending_users'   => getPendingUsers(),
            'publish_alerts'  => getPublishJobsNeedingAttention($ownerId, 10),
            'schedule_alerts' => getScheduleJobsNeedingAttention($ownerId, 10),
        ]);
    }

    $openTasks = array_values(array_filter(getTasksForAssignee($userId), static fn ($t) => $t['status'] !== 'done'));
    jsonResponse($base + [
        'open_tasks'      => $openTasks,
        'open_task_count' => count($openTasks),
        // An editor only sees (and can retry) their OWN failed jobs.
        'publish_alerts'  => getPublishJobsNeedingAttention($ownerId, 10, $userId),
        'schedule_alerts' => getScheduleJobsNeedingAttention($ownerId, 10, $userId),
        'scheduled_posts' => fetchPublerScheduledPosts($userId, 10),
    ]);
}

/** Recent website posts: the team's for admins, an editor's own otherwise. */
function dashboard_postsAction(): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $perPage = 10;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $isAdmin = isAdmin();

    $total      = $isAdmin ? getPublishedPostCount($ownerId) : getPublishedPostCountByPoster($ownerId, $userId);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $posts      = $isAdmin
        ? getPublishedPostsPage($ownerId, $perPage, ($page - 1) * $perPage)
        : getPublishedPostsByPoster($ownerId, $userId, $perPage, ($page - 1) * $perPage);

    jsonResponse([
        'posts'       => $posts,
        'page'        => $page,
        'total_pages' => $totalPages,
        'total'       => $total,
    ]);
}
