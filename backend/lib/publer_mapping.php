<?php


// ---------------------------------------------------------------------------
// Publer page -> WordPress site mapping (stored per owner)
// ---------------------------------------------------------------------------
function ensurePublerMappingTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS publer_page_sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            publer_page_id VARCHAR(64) NOT NULL,
            site_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_page (user_id, publer_page_id),
            CONSTRAINT fk_pps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pps_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ensured = true;
}


/** Return [publer_page_id => site_id] for the user. */
function getPublerMappings(int $userId): array
{
    ensurePublerMappingTable();
    $stmt = getDb()->prepare('SELECT publer_page_id, site_id FROM publer_page_sites WHERE user_id = :uid');
    $stmt->execute(['uid' => $userId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['publer_page_id']] = (int) $row['site_id'];
    }
    return $map;
}


/** Upsert (or remove when $siteId <= 0) a page -> site mapping. */
function savePublerMapping(int $userId, string $pageId, int $siteId): void
{
    $db = getDb();
    if ($siteId <= 0) {
        $stmt = $db->prepare('DELETE FROM publer_page_sites WHERE user_id = :uid AND publer_page_id = :pid');
        $stmt->execute(['uid' => $userId, 'pid' => $pageId]);
        return;
    }
    $stmt = $db->prepare(
        'INSERT INTO publer_page_sites (user_id, publer_page_id, site_id)
         VALUES (:uid, :pid, :sid)
         ON DUPLICATE KEY UPDATE site_id = VALUES(site_id)'
    );
    $stmt->execute(['uid' => $userId, 'pid' => $pageId, 'sid' => $siteId]);
}


/** Site ids mapped to any Facebook page in the given workspace (for a user's key). */
function getMappedSiteIdsForWorkspace(int $ownerId, int $forUserId, string $workspaceId): array
{
    if ($workspaceId === '') {
        return [];
    }
    $mappings = getPublerMappings($ownerId);                       // pageId => siteId
    $pages    = fetchPublerFacebookPages($forUserId, $workspaceId); // pages of THIS workspace, explicitly
    $siteIds  = [];
    foreach ($pages as $p) {
        if (!empty($mappings[$p['id']])) {
            $siteIds[] = (int) $mappings[$p['id']];
        }
    }
    return array_values(array_unique($siteIds));
}


/**
 * Site ids mapped to any Facebook page across EVERY workspace this user is
 * allowed to use (not just their single currently-active one) — the full set
 * of WordPress sites an editor may publish to. Used to gate "Publish to
 * Websites" for editors: a site with no page mapped to any of their allowed
 * workspaces is off-limits, even if it belongs to the same account owner.
 */
function getMappedSiteIdsForAllowedWorkspaces(int $ownerId, int $forUserId): array
{
    $siteIds = [];
    foreach (getAllowedWorkspaceIds($forUserId) as $wsId) {
        foreach (getMappedSiteIdsForWorkspace($ownerId, $forUserId, (string) $wsId) as $siteId) {
            $siteIds[] = $siteId;
        }
    }
    return array_values(array_unique($siteIds));
}


/** Fetch a single WordPress post's permalink by its ID. Returns the link (always non-null). */
function getWordPressPostLink(array $site, int $postId): ?string
{
    $url = wordPressRestUrl($site, 'wp/v2/posts/' . $postId . '?_fields=link');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // many WP sites redirect http->https/www
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        $data = json_decode($raw, true);
        if (is_array($data) && !empty($data['link'])) {
            return (string) $data['link'];
        }
    }
    // Fallback: ?p=ID reliably redirects to the published post's permalink.
    return rtrim($site['site_url'], '/') . '/?p=' . $postId;
}


/**
 * Link to the latest post THIS app published to the given site (from the
 * published_posts log). Posts created manually / by other users on the website
 * are ignored. Returns null when the app never published to this site.
 */
/**
 * Link to the latest post THIS SPECIFIC USER published through the app to the
 * given site — scoped by posted_by, not just the shared team/owner, so one
 * editor's "mapped website link" first-comment never picks up a link to a
 * different editor's article. Returns null when $postedBy never published to
 * this site via the app (even if a teammate has).
 */
function getLatestAppPublishedLink(int $ownerId, int $siteId, int $postedBy): ?string
{
    $stmt = getDb()->prepare(
        'SELECT remote_post_id FROM published_posts
         WHERE user_id = :uid AND site_id = :sid AND posted_by = :posted_by
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $ownerId, 'sid' => $siteId, 'posted_by' => $postedBy]);
    $remoteId = $stmt->fetchColumn();
    if (!$remoteId) {
        return null; // this user hasn't published to this site through the app
    }

    $site = getWordPressSiteById($siteId, $ownerId);
    return $site ? getWordPressPostLink($site, (int) $remoteId) : null;
}


/** Combine an optional manual comment with a post link into one comment string. */
function buildFirstComment(string $manual, ?string $link): string
{
    $parts = [];
    if ($manual !== '') {
        $parts[] = $manual;
    }
    if ($link !== null && $link !== '') {
        $parts[] = $link;
    }
    return trim(implode("\n", $parts));
}
