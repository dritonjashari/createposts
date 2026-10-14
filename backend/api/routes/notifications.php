<?php
// Notifications. Mirrors the nav bell in functions.php's renderNav() +
// notifications.php — currently just "you were assigned a task".

function notifications_listAction(): void
{
    requireLoginApi();
    $userId = currentUserId();
    jsonResponse([
        'notifications' => getNotificationsForUser($userId, 15),
        'unread_count'  => getUnreadNotificationCount($userId),
    ]);
}

function notifications_readAction(string $id): void
{
    requireLoginApi();
    markNotificationRead((int) $id, currentUserId());
    jsonResponse(['unread_count' => getUnreadNotificationCount(currentUserId())]);
}

function notifications_readAllAction(): void
{
    requireLoginApi();
    markAllNotificationsRead(currentUserId());
    jsonResponse(['unread_count' => 0]);
}
