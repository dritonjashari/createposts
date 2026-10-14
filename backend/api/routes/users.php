<?php
// Users & Roles. Mirrors users.php — admin-only.

function users_listAction(): void
{
    requireAdminApi();
    $selfId = currentUserId();

    $users = getAllUsers();
    foreach ($users as &$u) {
        // null = never restricted (all workspaces); [] = explicitly none.
        $raw   = getSetting((int) $u['id'], 'publer_workspaces', null);
        $ownWs = $raw === null ? null : json_decode($raw, true);
        $u['workspaces_restricted'] = $raw !== null;
        $u['workspace_ids'] = is_array($ownWs) ? array_values(array_map('strval', $ownWs)) : [];
    }
    unset($u);

    jsonResponse([
        'users'      => $users,
        'workspaces' => getPublerWorkspaces($selfId),
        'self_id'    => $selfId,
    ]);
}

function users_createAction(): void
{
    requireAdminApi();
    $body   = jsonBody();
    $result = adminCreateUser(
        (string) ($body['name'] ?? ''),
        (string) ($body['email'] ?? ''),
        (string) ($body['password'] ?? ''),
        (string) ($body['role'] ?? 'editor'),
        currentUserId()
    );
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']], 201);
}

function users_approveAction(string $id): void
{
    requireAdminApi();
    $result = approveUserAccount((int) $id);
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']]);
}

function users_setRoleAction(string $id): void
{
    requireAdminApi();
    $targetId = (int) $id;
    if ($targetId === currentUserId()) {
        jsonError(422, 'You cannot change your own role.');
    }
    $body = jsonBody();
    $role = (string) ($body['role'] ?? '');
    if (!in_array($role, ['admin', 'editor'], true)) {
        jsonError(422, 'Invalid role.');
    }
    $result = setUserRole($targetId, $role, currentUserId());
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']]);
}

function users_deleteAction(string $id): void
{
    requireAdminApi();
    $targetId = (int) $id;
    if ($targetId === currentUserId()) {
        jsonError(422, 'You cannot delete your own account.');
    }
    $result = deleteUserAccount($targetId, currentUserId());
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']]);
}

function users_setWorkspacesAction(string $id): void
{
    requireAdminApi();
    $targetId = (int) $id;
    if (!userExists($targetId)) {
        jsonError(404, 'User not found.');
    }
    $body = jsonBody();

    // `workspaces: null` = lift the restriction (all workspaces); an array =
    // restrict to exactly those ids — an empty array means no access at all.
    if (array_key_exists('workspaces', $body) && $body['workspaces'] === null) {
        deleteSetting($targetId, 'publer_workspaces');
        jsonResponse(['message' => 'Workspace access updated — all workspaces allowed.']);
    }

    $ids = array_values(array_unique(array_filter(array_map('strval', (array) ($body['workspaces'] ?? [])), static fn ($v) => $v !== '')));
    setSetting($targetId, 'publer_workspaces', json_encode($ids));

    // If their active workspace is no longer in the allowed list, clear it.
    $active = (string) getSetting($targetId, 'publer_workspace_id', '');
    if ($active !== '' && !in_array($active, $ids, true)) {
        setSetting($targetId, 'publer_workspace_id', '');
    }
    jsonResponse(['message' => empty($ids)
        ? 'Workspace access updated — this user now has no workspace access.'
        : 'Workspace access updated.']);
}
