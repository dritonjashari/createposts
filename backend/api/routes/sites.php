<?php
// Websites (WordPress sites). Mirrors sites.php — admin-only.

function sites_listAction(): void
{
    requireAdminApi();
    jsonResponse(['sites' => getWordPressSitesForUser(getSiteOwnerId(currentUserId()))]);
}

function sites_createAction(): void
{
    requireAdminApi();
    $body = jsonBody();
    $result = addWordPressSite(
        getSiteOwnerId(currentUserId()),
        trim((string) ($body['site_name'] ?? '')),
        trim((string) ($body['site_url'] ?? '')),
        trim((string) ($body['username'] ?? '')),
        trim((string) ($body['application_password'] ?? ''))
    );
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message'], 'sites' => getWordPressSitesForUser(getSiteOwnerId(currentUserId()))], 201);
}

function sites_deleteAction(string $id): void
{
    requireAdminApi();
    deleteWordPressSite((int) $id, getSiteOwnerId(currentUserId()));
    jsonResponse(['message' => 'Site removed.']);
}
