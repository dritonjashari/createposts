<?php


// ---------------------------------------------------------------------------
// System announcements — admin-created banners shown to their whole team
// (themselves + editors) on chosen date(s), e.g. "Office closed for the
// holiday on Dec 25". Scoped by owner_id, same tenant model as everything
// else in the app (an editor's "owner" is the admin who created them).
// target_user_id is optional: NULL means "everyone on the team" (the
// default); set to a specific user id to send it to just that one person.
// ---------------------------------------------------------------------------
function ensureAnnouncementsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            created_by INT NOT NULL,
            target_user_id INT NULL,
            message TEXT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'info',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ann_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    getDb()->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS target_user_id INT NULL AFTER created_by");
    $ensured = true;
}


/** All announcements an owner has created (for the System page management list). */
function getAnnouncementsForOwner(int $ownerId): array
{
    ensureAnnouncementsTable();
    $stmt = getDb()->prepare(
        'SELECT a.*, u.name AS target_user_name
         FROM announcements a
         LEFT JOIN users u ON u.id = a.target_user_id
         WHERE a.owner_id = :oid
         ORDER BY a.start_date DESC, a.id DESC'
    );
    $stmt->execute(['oid' => $ownerId]);
    return $stmt->fetchAll();
}


/**
 * Announcements currently in their active date window for this specific
 * user — what their banner shows. Includes team-wide ones (target_user_id
 * IS NULL) plus any addressed specifically to them.
 */
function getActiveAnnouncementsForUser(int $userId, int $ownerId): array
{
    ensureAnnouncementsTable();
    $stmt = getDb()->prepare(
        'SELECT * FROM announcements
         WHERE owner_id = :oid
           AND (target_user_id IS NULL OR target_user_id = :uid)
           AND CURDATE() BETWEEN start_date AND end_date
         ORDER BY start_date ASC, id ASC'
    );
    $stmt->execute(['oid' => $ownerId, 'uid' => $userId]);
    return $stmt->fetchAll();
}


function createAnnouncement(
    int $ownerId,
    int $createdBy,
    string $message,
    string $kind,
    string $startDate,
    string $endDate,
    ?int $targetUserId = null
): array {
    ensureAnnouncementsTable();

    $message = trim($message);
    if ($message === '') {
        return ['success' => false, 'message' => 'Announcement text is required.'];
    }
    $kind = in_array($kind, ['info', 'warning', 'success', 'danger'], true) ? $kind : 'info';

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end   = DateTime::createFromFormat('Y-m-d', $endDate !== '' ? $endDate : $startDate);
    if (!$start || !$end) {
        return ['success' => false, 'message' => 'Please choose a valid date.'];
    }
    if ($end < $start) {
        return ['success' => false, 'message' => 'End date cannot be before the start date.'];
    }

    // Only allow targeting a user who actually belongs to this admin's team —
    // never let a client-supplied id reach across tenants.
    if ($targetUserId !== null) {
        $team = getTeamMembers($ownerId);
        $validIds = array_map(static fn ($u) => (int) $u['id'], $team);
        if (!in_array($targetUserId, $validIds, true)) {
            return ['success' => false, 'message' => 'That user is not part of your team.'];
        }
    }

    $stmt = getDb()->prepare(
        'INSERT INTO announcements (owner_id, created_by, target_user_id, message, kind, start_date, end_date)
         VALUES (:oid, :cb, :tuid, :msg, :kind, :start, :end)'
    );
    $stmt->execute([
        'oid'   => $ownerId,
        'cb'    => $createdBy,
        'tuid'  => $targetUserId,
        'msg'   => $message,
        'kind'  => $kind,
        'start' => $start->format('Y-m-d'),
        'end'   => $end->format('Y-m-d'),
    ]);

    return ['success' => true, 'message' => 'Announcement created.', 'id' => (int) getDb()->lastInsertId()];
}


function deleteAnnouncement(int $id, int $ownerId): bool
{
    ensureAnnouncementsTable();
    $stmt = getDb()->prepare('DELETE FROM announcements WHERE id = :id AND owner_id = :oid');
    $stmt->execute(['id' => $id, 'oid' => $ownerId]);
    return $stmt->rowCount() > 0;
}
