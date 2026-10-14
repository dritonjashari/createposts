<?php


// ---------------------------------------------------------------------------
// Post categories (per website or all websites; one can be the default)
// ---------------------------------------------------------------------------
function ensureCategoriesTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS post_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            site_id INT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_cat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_cat_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ensured = true;
}


/** All categories for an owner (with the site name for per-site ones). */
function getCategories(int $ownerId): array
{
    ensureCategoriesTable();
    $stmt = getDb()->prepare(
        'SELECT c.*, ws.site_name FROM post_categories c
         LEFT JOIN wordpress_sites ws ON ws.id = c.site_id
         WHERE c.user_id = :u ORDER BY c.name'
    );
    $stmt->execute(['u' => $ownerId]);
    return $stmt->fetchAll();
}


/** Categories that apply to a given site (site-specific + all-websites ones). */
function getCategoriesForSite(int $ownerId, int $siteId): array
{
    ensureCategoriesTable();
    $stmt = getDb()->prepare(
        'SELECT * FROM post_categories WHERE user_id = :u AND (site_id IS NULL OR site_id = :s) ORDER BY name'
    );
    $stmt->execute(['u' => $ownerId, 's' => $siteId]);
    return $stmt->fetchAll();
}


function addCategory(int $ownerId, string $name, ?int $siteId): void
{
    ensureCategoriesTable();
    $stmt = getDb()->prepare('INSERT INTO post_categories (user_id, name, site_id) VALUES (:u, :n, :s)');
    $stmt->execute(['u' => $ownerId, 'n' => $name, 's' => $siteId ?: null]);
}


function deleteCategory(int $ownerId, int $id): void
{
    $stmt = getDb()->prepare('DELETE FROM post_categories WHERE id = :id AND user_id = :u');
    $stmt->execute(['id' => $id, 'u' => $ownerId]);
}


function setDefaultCategory(int $ownerId, int $id): void
{
    $db = getDb();
    $db->prepare('UPDATE post_categories SET is_default = 0 WHERE user_id = :u')->execute(['u' => $ownerId]);
    $db->prepare('UPDATE post_categories SET is_default = 1 WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $ownerId]);
}


function getDefaultCategory(int $ownerId): ?array
{
    ensureCategoriesTable();
    $stmt = getDb()->prepare('SELECT * FROM post_categories WHERE user_id = :u AND is_default = 1 LIMIT 1');
    $stmt->execute(['u' => $ownerId]);
    return $stmt->fetch() ?: null;
}


/** Find (or create) a WordPress category by name on a site. Returns the term id or null. */
function resolveWordPressCategoryId(array $site, string $name): ?int
{
    $auth = 'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']);

    // 1) Look for an existing category with this name.
    $ch = curl_init(wordPressRestUrl($site, 'wp/v2/categories?search=' . rawurlencode($name) . '&per_page=100'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$auth]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $arr = json_decode($raw, true);
        if (is_array($arr)) {
            foreach ($arr as $cat) {
                if (isset($cat['name']) && strcasecmp($cat['name'], $name) === 0) {
                    return (int) $cat['id'];
                }
            }
        }
    }

    // 2) Create it.
    $ch = curl_init(wordPressRestUrl($site, 'wp/v2/categories'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$auth, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $name]));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode($raw, true);
    if (($code === 201 || $code === 200) && !empty($d['id'])) {
        return (int) $d['id'];
    }
    // WordPress returns the existing term id when the name already exists.
    if (!empty($d['data']['term_id'])) {
        return (int) $d['data']['term_id'];
    }
    return null;
}
