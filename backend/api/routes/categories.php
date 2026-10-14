<?php
// Post categories. Mirrors categories.php — admin-only.

function categories_listAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    jsonResponse([
        'categories' => getCategories($userId),
        'sites'      => getWordPressSitesForUser($userId),
    ]);
}

function categories_createAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();
    $name   = trim((string) ($body['name'] ?? ''));
    $siteId = (int) ($body['site_id'] ?? 0);

    if ($name === '') {
        jsonError(422, 'Please enter a category name.');
    }

    addCategory($userId, $name, $siteId > 0 ? $siteId : null);
    jsonResponse(['message' => 'Category added.', 'categories' => getCategories($userId)], 201);
}

function categories_deleteAction(string $id): void
{
    requireAdminApi();
    deleteCategory(getSiteOwnerId(currentUserId()), (int) $id);
    jsonResponse(['message' => 'Category removed.']);
}

function categories_setDefaultAction(string $id): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    setDefaultCategory($userId, (int) $id);
    jsonResponse(['message' => 'Default category updated.', 'categories' => getCategories($userId)]);
}
