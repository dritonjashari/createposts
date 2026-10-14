<?php


// ---------------------------------------------------------------------------
// Notifications (currently just "you were assigned a task" — points at a
// task id; each frontend builds its own link from that id).
// ---------------------------------------------------------------------------
function ensureNotificationsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message VARCHAR(255) NOT NULL,
            task_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notif_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ensured = true;
}


function createNotification(int $userId, string $message, ?int $taskId = null): void
{
    ensureNotificationsTable();
    $stmt = getDb()->prepare('INSERT INTO notifications (user_id, message, task_id) VALUES (:user_id, :message, :task_id)');
    $stmt->execute(['user_id' => $userId, 'message' => $message, 'task_id' => $taskId]);
}


function getUnreadNotificationCount(int $userId): int
{
    ensureNotificationsTable();
    $stmt = getDb()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}


/** Most recent notifications for a user (read and unread), newest first. */
function getNotificationsForUser(int $userId, int $limit = 15): array
{
    ensureNotificationsTable();
    $stmt = getDb()->prepare(
        'SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT ' . max(1, (int) $limit)
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}


/** Marks one notification read — scoped to $userId so you can't mark someone else's. */
function markNotificationRead(int $notificationId, int $userId): void
{
    ensureNotificationsTable();
    $stmt = getDb()->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
}


function markAllNotificationsRead(int $userId): void
{
    ensureNotificationsTable();
    $stmt = getDb()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
    $stmt->execute(['user_id' => $userId]);
}


/** Relative time like "5m ago" / "3d ago"; falls back to a date once it's old. */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    if ($diff < 604800) {
        return floor($diff / 86400) . 'd ago';
    }
    return date('M j', strtotime($datetime));
}
