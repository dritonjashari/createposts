<?php
// Facebook-page <-> WordPress-site mapping, plus the sidebar's workspace
// switcher (shown on every page): its list and the "switch active workspace"
// action.

/** The workspaces a user may switch between, and the active one. */
function workspace_state(int $userId): array
{
    return [
        'workspaces'          => getAllowedWorkspaces($userId),
        'active_workspace_id' => getActiveWorkspaceId($userId),
    ];
}

/** Kept cheap (no Facebook pages or queue reads), since the sidebar re-checks it every minute. */
function workspace_getAction(): void
{
    requireLoginApi();
    jsonResponse(workspace_state(currentUserId()));
}

function workspace_switchAction(): void
{
    requireLoginApi();
    $userId = currentUserId();
    $body   = jsonBody();
    $ws     = (string) ($body['workspace_id'] ?? $body['ws'] ?? '');
    if (!setActiveWorkspaceId($userId, $ws)) {
        // Typically an admin removed it since the switcher's list was loaded.
        jsonError(422, "You don't have access to that workspace.");
    }
    jsonResponse(workspace_state($userId));
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
