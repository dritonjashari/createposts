<?php
// Single front controller + explicit route table. No router dependency —
// stays greppable and matches the rest of this codebase's plain-PHP style.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/sites.php';
require_once __DIR__ . '/routes/categories.php';
require_once __DIR__ . '/routes/mapping.php';
require_once __DIR__ . '/routes/publish.php';
require_once __DIR__ . '/routes/publer.php';
require_once __DIR__ . '/routes/users.php';
require_once __DIR__ . '/routes/system.php';
require_once __DIR__ . '/routes/profile.php';
require_once __DIR__ . '/routes/dashboard.php';
require_once __DIR__ . '/routes/analytics.php';
require_once __DIR__ . '/routes/stats.php';
require_once __DIR__ . '/routes/scheduled.php';
require_once __DIR__ . '/routes/tasks.php';
require_once __DIR__ . '/routes/notifications.php';
require_once __DIR__ . '/routes/announcements.php';
require_once __DIR__ . '/routes/jobs.php';

// path => [handler function, allowed HTTP method]. {name} segments become
// named capture groups available in $params passed to the handler... for now
// handlers read $_GET/$_POST/php://input themselves, so params aren't piped
// through — kept simple until a route actually needs a path parameter.
$routes = [
    'GET  auth/me'        => 'auth_meAction',
    'POST auth/login'     => 'auth_loginAction',
    'POST auth/register'  => 'auth_registerAction',
    'POST auth/logout'    => 'auth_logoutAction',

    'GET  workspace'      => 'workspace_getAction',
    'POST workspace'      => 'workspace_switchAction',

    'GET    sites'        => 'sites_listAction',
    'POST   sites'        => 'sites_createAction',
    'DELETE sites/{id}'   => 'sites_deleteAction',

    'GET    categories'             => 'categories_listAction',
    'POST   categories'             => 'categories_createAction',
    'POST   categories/{id}/default' => 'categories_setDefaultAction',
    'DELETE categories/{id}'        => 'categories_deleteAction',

    'GET  mapping'        => 'mapping_getAction',
    'POST mapping'        => 'mapping_saveAction',

    'GET  publish/context'    => 'publish_contextAction',
    'POST publish'            => 'publish_createAction',
    'POST publish/inpaint'    => 'publish_inpaintAction',
    'POST publish/import'     => 'publish_importAction',
    'GET  publish/jobs'             => 'publish_jobsListAction',
    'GET  publish/jobs/{id}'        => 'publish_jobStatusAction',
    'POST publish/jobs/{id}/retry'  => 'publish_retryJobAction',
    'GET  publish/jobs/{id}/thumbnail' => 'publish_jobThumbnailAction',
    'GET  publish/jobs/{jobId}/sites/{siteId}/post' => 'publish_jobPostDetailAction',
    'POST publish/jobs/{jobId}/sites/{siteId}/post' => 'publish_jobPostUpdateAction',

    'GET  publer/context'    => 'publer_contextAction',
    'POST publer/schedule'   => 'publer_scheduleAction',
    'GET  publer/jobs/{id}'  => 'publer_jobStatusAction',

    'GET  jobs'                        => 'jobs_listAction',
    'POST jobs/{jobType}/{jobId}/retry' => 'jobs_retryAction',


    'GET    users'                   => 'users_listAction',
    'POST   users'                   => 'users_createAction',
    'POST   users/{id}/approve'      => 'users_approveAction',
    'POST   users/{id}/role'         => 'users_setRoleAction',
    'DELETE users/{id}'              => 'users_deleteAction',
    'POST   users/{id}/workspaces'   => 'users_setWorkspacesAction',

    'GET    system'                  => 'system_getAction',
    'POST   system/scheduling'       => 'system_saveSchedulingAction',
    'POST   system/workspace'        => 'system_saveWorkspaceAction',
    'POST   system/timeslots'        => 'system_addTimeslotAction',
    'DELETE system/timeslots/{id}'   => 'system_removeTimeslotAction',
    'POST   system/publer-key'       => 'system_savePublerKeyAction',
    'POST   system/openai'           => 'system_saveOpenAiAction',
    'POST   system/smtp'             => 'system_saveSmtpAction',

    'GET  profile'          => 'profile_getAction',
    'POST profile'          => 'profile_updateAction',
    'POST profile/password' => 'profile_changePasswordAction',

    'GET  dashboard'    => 'dashboard_getAction',
    'GET  dashboard/posts' => 'dashboard_postsAction',

    'GET  analytics'                    => 'analytics_getAction',
    'POST analytics/service-account'    => 'analytics_saveServiceAccountAction',
    'POST analytics/properties'         => 'analytics_savePropertiesAction',

    'GET  stats/publer' => 'stats_publerAction',

    'GET    scheduled-posts'              => 'scheduled_listAction',
    'DELETE scheduled-posts/{id}'         => 'scheduled_deleteAction',
    'PATCH  scheduled-posts/{id}'         => 'scheduled_rescheduleAction',
    'POST   scheduled-posts/{id}/edit'    => 'scheduled_editAction',
    'POST   scheduled-posts/{id}/repost'  => 'scheduled_repostAction',

    'GET    tasks'              => 'tasks_listAction',
    'GET    tasks/{id}'         => 'tasks_getAction',
    'POST   tasks'              => 'tasks_createAction',
    'DELETE tasks/{id}'         => 'tasks_deleteAction',
    'POST   tasks/{id}/status'  => 'tasks_setStatusAction',
    'POST   tasks/{id}/notes'   => 'tasks_addNoteAction',

    'GET  notifications'            => 'notifications_listAction',
    'POST notifications/{id}/read'  => 'notifications_readAction',
    'POST notifications/read-all'   => 'notifications_readAllAction',

    'GET    announcements'         => 'announcements_listAction',
    'GET    announcements/active'  => 'announcements_activeAction',
    'POST   announcements'        => 'announcements_createAction',
    'DELETE announcements/{id}'   => 'announcements_deleteAction',
];

/** Compile a "sites/{id}" style path into a regex with a capture group per {name}. */
function compileRoutePath(string $patPath): string
{
    $parts = preg_split('#(\{[a-zA-Z_]+\})#', $patPath, -1, PREG_SPLIT_DELIM_CAPTURE);
    $regex = '';
    foreach ($parts as $part) {
        $regex .= preg_match('#^\{[a-zA-Z_]+\}$#', $part) ? '([^/]+)' : preg_quote($part, '#');
    }
    return '#^' . $regex . '$#';
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
// Strip everything up to and including the first "api/" segment, so this
// works whether the app is deployed at the domain root (api.example.com/)
// or under a subdirectory (example.com/createposts/api/...), and also when
// index.php is hit directly (.../api/index.php/auth/me).
$path = preg_replace('#^.*?api/(index\.php/)?#', '', $path);

foreach ($routes as $pattern => $handler) {
    [$patMethod, $patPath] = explode(' ', $pattern, 2);
    if (strtoupper(trim($patMethod)) !== $method) {
        continue;
    }
    if (preg_match(compileRoutePath(trim($patPath)), $path, $matches)) {
        array_shift($matches);
        call_user_func_array($handler, $matches);
        exit;
    }
}

jsonError(404, 'Not found');
