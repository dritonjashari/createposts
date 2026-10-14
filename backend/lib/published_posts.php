<?php


function logPublishedPost(int $userId, int $siteId, string $title, int $remotePostId, string $status, ?int $postedBy = null, ?string $siteName = null, ?string $siteUrl = null): void
{
    $stmt = getDb()->prepare(
        'INSERT INTO published_posts (user_id, site_id, title, remote_post_id, status, posted_by, site_name_snapshot, site_url_snapshot)
         VALUES (:user_id, :site_id, :title, :remote_post_id, :status, :posted_by, :site_name, :site_url)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'site_id' => $siteId,
        'title' => $title,
        'remote_post_id' => $remotePostId,
        'status' => $status,
        'posted_by' => $postedBy ?? $userId,
        'site_name' => $siteName,
        'site_url' => $siteUrl,
    ]);
}


/**
 * Update an existing published_posts row (e.g. promote a draft log entry to
 * 'publish' after Phase 2 finishes). Falls back to inserting if no matching
 * row exists (shouldn't happen normally, but safe).
 */
function updatePublishedPostRecord(int $ownerId, int $siteId, int $remotePostId, string $title, string $status, int $postedBy, ?string $siteName = null, ?string $siteUrl = null): void
{
    $stmt = getDb()->prepare(
        'UPDATE published_posts
            SET title = :title, status = :status, posted_by = :posted_by,
                site_name_snapshot = COALESCE(:site_name, site_name_snapshot),
                site_url_snapshot = COALESCE(:site_url, site_url_snapshot)
          WHERE user_id = :user_id AND site_id = :site_id AND remote_post_id = :remote_post_id'
    );
    $stmt->execute([
        'title'          => $title,
        'status'         => $status,
        'posted_by'      => $postedBy,
        'site_name'      => $siteName,
        'site_url'       => $siteUrl,
        'user_id'        => $ownerId,
        'site_id'        => $siteId,
        'remote_post_id' => $remotePostId,
    ]);
    if ($stmt->rowCount() === 0) {
        logPublishedPost($ownerId, $siteId, $title, $remotePostId, $status, $postedBy, $siteName, $siteUrl);
    }
}


function getPublishedPostsForUser(int $userId): array
{
    $stmt = getDb()->prepare(
        'SELECT pp.*, COALESCE(ws.site_name, pp.site_name_snapshot) AS site_name,
                COALESCE(ws.site_url, pp.site_url_snapshot) AS site_url, u.name AS posted_by_name
         FROM published_posts pp
         LEFT JOIN wordpress_sites ws ON ws.id = pp.site_id
         LEFT JOIN users u ON u.id = pp.posted_by
         WHERE pp.user_id = :user_id
         ORDER BY pp.created_at DESC LIMIT 20'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}


/** A page of a user's published posts (with poster name), for pagination. */
function getPublishedPostsPage(int $userId, int $limit, int $offset): array
{
    $stmt = getDb()->prepare(
        'SELECT pp.*, COALESCE(ws.site_name, pp.site_name_snapshot) AS site_name,
                COALESCE(ws.site_url, pp.site_url_snapshot) AS site_url, u.name AS posted_by_name
         FROM published_posts pp
         LEFT JOIN wordpress_sites ws ON ws.id = pp.site_id
         LEFT JOIN users u ON u.id = pp.posted_by
         WHERE pp.user_id = :user_id
         ORDER BY pp.created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}


/** Per-poster breakdown of published post counts for the admin's account. */
function getPublishBreakdownByPoster(int $adminId): array
{
    $stmt = getDb()->prepare(
        'SELECT u.id, u.name, COUNT(pp.id) AS post_count, MAX(pp.created_at) AS last_post
         FROM published_posts pp
         JOIN users u ON u.id = pp.posted_by
         WHERE pp.user_id = :admin_id
         GROUP BY pp.posted_by, u.id, u.name
         ORDER BY post_count DESC'
    );
    $stmt->execute(['admin_id' => $adminId]);
    return $stmt->fetchAll();
}


/** Total number of posts this user has published to websites through the app. */
function getPublishedPostCount(int $userId): int
{
    $stmt = getDb()->prepare('SELECT COUNT(*) FROM published_posts WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}


/** Posts published through the app by one specific person (e.g. an editor), paginated. */
function getPublishedPostsByPoster(int $ownerId, int $postedBy, int $limit, int $offset): array
{
    $stmt = getDb()->prepare(
        'SELECT pp.*, COALESCE(ws.site_name, pp.site_name_snapshot) AS site_name,
                COALESCE(ws.site_url, pp.site_url_snapshot) AS site_url
         FROM published_posts pp
         LEFT JOIN wordpress_sites ws ON ws.id = pp.site_id
         WHERE pp.user_id = :user_id AND pp.posted_by = :posted_by
         ORDER BY pp.created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':user_id', $ownerId, PDO::PARAM_INT);
    $stmt->bindValue(':posted_by', $postedBy, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}


/** Count of posts published through the app by one specific person. */
function getPublishedPostCountByPoster(int $ownerId, int $postedBy): int
{
    $stmt = getDb()->prepare('SELECT COUNT(*) FROM published_posts WHERE user_id = :user_id AND posted_by = :posted_by');
    $stmt->execute(['user_id' => $ownerId, 'posted_by' => $postedBy]);
    return (int) $stmt->fetchColumn();
}
