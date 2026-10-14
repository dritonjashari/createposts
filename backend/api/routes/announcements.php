<?php
// System announcements — admin creates, whole team (themselves + editors) or
// a single chosen team member sees.

function announcements_listAction(): void
{
    requireAdminApi();
    $ownerId = getSiteOwnerId(currentUserId());
    jsonResponse([
        'announcements' => getAnnouncementsForOwner($ownerId),
        'team_members'  => getTeamMembers($ownerId),
    ]);
}

function announcements_activeAction(): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    jsonResponse(['announcements' => getActiveAnnouncementsForUser($userId, $ownerId)]);
}

function announcements_createAction(): void
{
    requireAdminApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $body    = jsonBody();

    $targetRaw = $body['target_user_id'] ?? null;
    $targetUserId = ($targetRaw !== null && $targetRaw !== '') ? (int) $targetRaw : null;

    $result = createAnnouncement(
        $ownerId,
        $userId,
        (string) ($body['message'] ?? ''),
        (string) ($body['kind'] ?? 'info'),
        (string) ($body['start_date'] ?? ''),
        (string) ($body['end_date'] ?? ''),
        $targetUserId
    );
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message'], 'id' => $result['id']], 201);
}

function announcements_deleteAction(string $id): void
{
    requireAdminApi();
    $ownerId = getSiteOwnerId(currentUserId());
    $ok = deleteAnnouncement((int) $id, $ownerId);
    if (!$ok) {
        jsonError(404, 'Announcement not found.');
    }
    jsonResponse(['message' => 'Announcement deleted.']);
}
