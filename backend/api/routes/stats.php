<?php
// Publer / Facebook analytics statistics — admin only.

function stats_publerAction(): void
{
    requireAdminApi();
    $userId      = currentUserId();
    $workspaceId = fetchPublerWorkspaceId($userId);
    $apiKey      = getPublerApiKey($userId);
    $accountId   = trim($_GET['account_id'] ?? '');
    $period      = max(7, min(365, (int) ($_GET['period'] ?? 30)));
    $dateEnd     = date('Y-m-d');
    $dateStart   = date('Y-m-d', strtotime("-{$period} days"));

    $pages = fetchPublerFacebookPages($userId, $workspaceId);

    if ($workspaceId === '') {
        jsonError(422, 'No active Publer workspace. Set one on the System page.');
    }

    $overview  = null;
    $postStats = [];

    if ($accountId !== '') {
        // Publer analytics overview for the selected account
        $qs       = http_build_query(['account_id' => $accountId, 'date_start' => $dateStart, 'date_end' => $dateEnd]);
        $overview = publerHttpGet('analytics?' . $qs, $workspaceId, $apiKey);

        // Recent published posts for this account
        $pqs   = http_build_query(['account_id' => $accountId, 'state' => 'published', 'limit' => 25]);
        $pData = publerHttpGet('posts?' . $pqs, $workspaceId, $apiKey);
        $pList = is_array($pData) ? ($pData['posts'] ?? (is_array($pData) ? $pData : [])) : [];
        foreach ((array) $pList as $p) {
            if (is_array($p)) {
                $postStats[] = $p;
            }
        }
    }

    jsonResponse([
        'pages'      => $pages,
        'overview'   => $overview,
        'posts'      => $postStats,
        'period'     => $period,
        'date_start' => $dateStart,
        'date_end'   => $dateEnd,
        'account_id' => $accountId,
    ]);
}
