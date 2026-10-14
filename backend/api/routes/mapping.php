<?php
// Facebook-page <-> WordPress-site mapping, plus the shared "switch active
// workspace" action used by the navbar switcher on every page (mirrors
// switch_workspace.php, but returns JSON instead of a redirect).

function workspace_switchAction(): void
{
    requireLoginApi();
    $userId = currentUserId();
    $body   = jsonBody();
    $ws     = (string) ($body['workspace_id'] ?? $body['ws'] ?? '');
    if ($ws !== '') {
        setActiveWorkspaceId($userId, $ws); // ignores workspaces the user isn't allowed to use
    }
    jsonResponse(['active_workspace_id' => getActiveWorkspaceId($userId)]);
}

function mapping_getAction(): void
{
    requireAdminApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    ensurePublerMappingTable();

    $allowedWorkspaces = getAllowedWorkspaces($userId);
    $activeWorkspace   = fetchPublerWorkspaceId($userId);
    $wsName            = '';
    foreach ($allowedWorkspaces as $w) { if ($w['id'] === $activeWorkspace) { $wsName = $w['name']; break; } }

    jsonResponse([
        'workspaces'           => $allowedWorkspaces,
        'active_workspace_id'  => $activeWorkspace,
        'active_workspace_name' => $wsName,
        'facebook_pages'       => fetchPublerFacebookPages($userId, $activeWorkspace),
        'sites'                => getWordPressSitesForUser($ownerId),
        'mappings'             => getPublerMappings($ownerId),
    ]);
}

function mapping_saveAction(): void
{
    requireAdminApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $body    = jsonBody();

    foreach ((array) ($body['mapping'] ?? []) as $pageId => $siteId) {
        savePublerMapping($ownerId, (string) $pageId, (int) $siteId);
    }

    jsonResponse(['message' => 'Website mappings saved for this workspace.', 'mappings' => getPublerMappings($ownerId)]);
}
