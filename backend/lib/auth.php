<?php


/**
 * The logged-in user's row, read fresh from the DB once per request (keyed by
 * id so a login mid-request picks up the new user). Null if the session has no
 * user, or the account was deleted or is not approved — so an admin's role
 * change / delete takes effect on the user's very next request instead of
 * whatever was cached in their session at login time.
 */
function sessionUserRow(): ?array
{
    static $cache = [];
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $stmt = getDb()->prepare('SELECT id, name, email, role, status FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch() ?: null;
        if ($row !== null && ($row['status'] ?? 'active') === 'pending') {
            $row = null;
        }
        $cache[$id] = $row;
    }
    return $cache[$id];
}


function isLoggedIn(): bool
{
    return sessionUserRow() !== null;
}


function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}


function currentUser(): ?array
{
    $row = sessionUserRow();
    if ($row === null) {
        return null;
    }
    unset($row['status']);
    return $row;
}


/** Current user's role ('admin' or 'editor'), always the live DB value. */
function currentUserRole(): string
{
    $row = sessionUserRow();
    return ($row !== null && $row['role'] === 'admin') ? 'admin' : 'editor';
}


function isAdmin(): bool
{
    return currentUserRole() === 'admin';
}


/** Landing page for the current user's role. */
function homePage(): string
{
    return isAdmin() ? 'dashboard.php' : 'editor_dashboard.php';
}


/**
 * The user id that owns the WordPress sites, Publer/OpenAI keys, settings and
 * timeslots a user works with (the "team owner").
 *
 * - An admin nobody else created (created_by empty) owns their own data.
 * - An admin created by another admin — or an editor promoted to admin — is a
 *   co-admin of that admin's team and works with the same data. Previously
 *   every admin used only their own id, so promoting an editor cut them off
 *   from the team's sites and Publer workspaces.
 * - An editor uses the team of the admin who created them.
 *
 * The created_by chain is followed up to the top admin; links that point at a
 * missing or non-admin user are ignored, and loops are cut off.
 */
function getSiteOwnerId(int $userId): int
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $db   = getDb();
    $stmt = $db->prepare('SELECT role, created_by FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $userId;
    }

    // The admin a created_by value points at, or null if it isn't one.
    $adminRow = static function ($id) use ($db): ?array {
        if (empty($id)) {
            return null;
        }
        $stmt = $db->prepare("SELECT id, created_by FROM users WHERE id = :id AND role = 'admin'");
        $stmt->execute(['id' => (int) $id]);
        return $stmt->fetch() ?: null;
    };

    if ($row['role'] === 'admin') {
        $owner = ['id' => $userId, 'created_by' => $row['created_by']];
    } else {
        $owner = $adminRow($row['created_by']);
        if ($owner === null) {
            // Creator was deleted or demoted: fall back to the earliest admin,
            // rather than leaving the editor with no sites/key/workspaces.
            $owner = $adminRow($db->query("SELECT MIN(id) FROM users WHERE role = 'admin'")->fetchColumn());
            if ($owner === null) {
                return $cache[$userId] = $userId;
            }
        }
    }

    // Walk up admin -> creating admin to the top of the team.
    $seen = [(int) $owner['id'] => true];
    for ($i = 0; $i < 20; $i++) {
        $parent = $adminRow($owner['created_by']);
        if ($parent === null || isset($seen[(int) $parent['id']])) {
            break;
        }
        $seen[(int) $parent['id']] = true;
        $owner = $parent;
    }

    return $cache[$userId] = (int) $owner['id'];
}


/**
 * The Publer API key to use for a user: admins use their own key (saved on the
 * System page); editors use the key of the admin who created them. Falls back
 * to the app-wide default key if the owning admin hasn't saved one yet.
 */
function getPublerApiKey(int $userId): string
{
    $ownerId = getSiteOwnerId($userId);
    $stmt = getDb()->prepare('SELECT publer_api_key FROM users WHERE id = :id');
    $stmt->execute(['id' => $ownerId]);
    $key = trim((string) $stmt->fetchColumn());
    return $key !== '' ? $key : PUBLER_API_KEY;
}


/** Verify a Publer API key by calling a lightweight authenticated endpoint. Null = could not verify. */
function publerApiKeyValid(string $apiKey): ?bool
{
    if ($apiKey === '') {
        return null;
    }
    $ch = curl_init(PUBLER_API_BASE . 'workspaces');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer-API ' . $apiKey,
        'Accept: application/json',
    ]);
    curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return null;
    }
    if ($code === 200) {
        return true;
    }
    if ($code === 401 || $code === 403) {
        return false;
    }
    return null;
}


/** Require an admin; editors are bounced to a page they are allowed to use. */
function requireAdmin(): void
{
    requireLogin();
    if (!isAdmin()) {
        setFlash('danger', 'You do not have permission to access that page.');
        header('Location: ' . homePage());
        exit;
    }
}


// ---------------------------------------------------------------------------
// Profile updates
// ---------------------------------------------------------------------------
function updateUserProfile(int $userId, string $name, string $email): array
{
    $name  = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || $email === '') {
        return ['success' => false, 'message' => 'Name and email are required.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    $db   = getDb();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
    $stmt->execute(['e' => $email, 'id' => $userId]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'That email is already used by another account.'];
    }

    $stmt = $db->prepare('UPDATE users SET name = :n, email = :e WHERE id = :id');
    $stmt->execute(['n' => $name, 'e' => $email, 'id' => $userId]);
    $_SESSION['user_name'] = $name;

    return ['success' => true, 'message' => 'Profile updated.'];
}


function changeUserPassword(int $userId, string $current, string $new, string $confirm): array
{
    if (strlen($new) < 6) {
        return ['success' => false, 'message' => 'New password must be at least 6 characters.'];
    }
    if ($new !== $confirm) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }

    $db   = getDb();
    $stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password'])) {
        return ['success' => false, 'message' => 'Your current password is incorrect.'];
    }

    $stmt = $db->prepare('UPDATE users SET password = :p WHERE id = :id');
    $stmt->execute(['p' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);

    return ['success' => true, 'message' => 'Password changed.'];
}
