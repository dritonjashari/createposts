<?php


// ---------------------------------------------------------------------------
// User management (admin)
// ---------------------------------------------------------------------------
function getAllUsers(): array
{
    return getDb()->query('SELECT id, name, email, role, status, created_at FROM users ORDER BY id ASC')->fetchAll();
}


function adminCreateUser(string $name, string $email, string $password, string $role, ?int $createdBy = null): array
{
    if (strlen($password) < 6) {
        return ['success' => false, 'message' => 'Password must be at least 6 characters.'];
    }
    $result = registerUser($name, $email, $password);
    if (!$result['success']) {
        return $result;
    }
    $role = $role === 'admin' ? 'admin' : 'editor';
    // Admin-created accounts don't go through the pending-approval gate —
    // the admin creating it IS the approval.
    $stmt = getDb()->prepare("UPDATE users SET role = :r, created_by = :c, status = 'active' WHERE email = :e");
    $stmt->execute(['r' => $role, 'c' => $createdBy, 'e' => strtolower(trim($email))]);
    return ['success' => true, 'message' => 'User created.'];
}


/** Self-registered accounts (public Register page) awaiting admin approval. */
function getPendingUsers(): array
{
    return getDb()->query("SELECT id, name, email, created_at FROM users WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll();
}


/** Approve a pending account so it can log in. */
function approveUserAccount(int $userId): array
{
    $stmt = getDb()->prepare("UPDATE users SET status = 'active' WHERE id = :id AND status = 'pending'");
    $stmt->execute(['id' => $userId]);
    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'User not found or already approved.'];
    }
    return ['success' => true, 'message' => 'User approved — they can now log in.'];
}


function userExists(int $userId): bool
{
    $stmt = getDb()->prepare('SELECT 1 FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    return (bool) $stmt->fetchColumn();
}


/**
 * Editors belong to the admin who created them (created_by) and inherit that
 * admin's sites, Publer key and workspaces. When that admin stops being an
 * admin (demoted or deleted), hand their editors over to $newOwnerId so they
 * aren't left pointing at someone who no longer owns anything.
 */
function reassignEditorsOf(int $formerAdminId, int $newOwnerId): void
{
    getDb()->prepare('UPDATE users SET created_by = :new WHERE created_by = :old AND id <> :new2')
        ->execute(['new' => $newOwnerId, 'old' => $formerAdminId, 'new2' => $newOwnerId]);
}


/** Who takes over a departing admin's editors: the acting admin, else the earliest remaining admin. */
function successorAdminId(array $adminIds, int $departingId, ?int $actingAdminId): int
{
    if ($actingAdminId && $actingAdminId !== $departingId) {
        return $actingAdminId;
    }
    return (int) min(array_diff(array_map('intval', $adminIds), [$departingId]));
}


function setUserRole(int $userId, string $role, ?int $actingAdminId = null): array
{
    $role = $role === 'admin' ? 'admin' : 'editor';
    $db   = getDb();

    $stmt = $db->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        return ['success' => false, 'message' => 'User not found.'];
    }
    if ($current === $role) {
        return ['success' => true, 'message' => 'Role unchanged.'];
    }

    $db->beginTransaction();
    try {
        if ($role === 'editor') {
            // Lock the admin rows so two concurrent demotions can't both pass the check.
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if (count($admins) <= 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You cannot demote the last admin.'];
            }
            $newOwner = successorAdminId($admins, $userId, $actingAdminId);
            reassignEditorsOf($userId, $newOwner);
            // The demoted user now works under an admin, like any other editor.
            $db->prepare("UPDATE users SET role = 'editor', created_by = :c WHERE id = :id")
                ->execute(['c' => $newOwner, 'id' => $userId]);
        } else {
            $db->prepare("UPDATE users SET role = 'admin' WHERE id = :id")->execute(['id' => $userId]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['success' => true, 'message' => $role === 'admin'
        ? 'Role updated — user is now an admin.'
        : 'Role updated — user is now an editor.'];
}


function deleteUserAccount(int $userId, ?int $actingAdminId = null): array
{
    $db   = getDb();
    $stmt = $db->prepare('SELECT role FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $role = $stmt->fetchColumn();
    if ($role === false) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    $db->beginTransaction();
    try {
        if ($role === 'admin') {
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if (count($admins) <= 1) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You cannot delete the last admin.'];
            }
            reassignEditorsOf($userId, successorAdminId($admins, $userId, $actingAdminId));
        }
        $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['success' => true, 'message' => 'User deleted.'];
}


function registerUser(string $name, string $email, string $password): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute(['email' => strtolower(trim($email))]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'An account with this email already exists.'];
    }

    // Self-registered accounts start 'pending' — an admin must approve them
    // (see approveUserAccount()) before they can log in. Exception: with no
    // admin at all (fresh install) nobody could ever approve anyone, so this
    // account becomes the first, active admin.
    $noAdmin = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0;
    $stmt = $db->prepare('INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, :status)');
    $stmt->execute([
        'name' => trim($name),
        'email' => strtolower(trim($email)),
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $noAdmin ? 'admin' : 'editor',
        'status' => $noAdmin ? 'active' : 'pending',
    ]);

    if ($noAdmin) {
        return ['success' => true, 'message' => 'Account created! You are the first user, so you are the admin — you can log in now.'];
    }
    return ['success' => true, 'message' => 'Account created! An admin needs to approve your account before you can log in.'];
}


function loginUser(string $email, string $password): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = :email');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    if (($user['status'] ?? 'active') === 'pending') {
        return ['success' => false, 'message' => 'Your account is awaiting admin approval. You will be able to log in once approved.'];
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    // Role is deliberately NOT cached in the session — see sessionUserRow().
    return ['success' => true, 'message' => 'Welcome back!'];
}


/**
 * Everyone who works in a team (shares the same getSiteOwnerId()), including
 * co-admins and editors created by any admin of the team — not just the users
 * one particular admin created. Optionally leaves one user (yourself) out.
 */
function getTeamMembers(int $ownerId, ?int $excludeId = null): array
{
    $rows = getDb()->query("SELECT id, name, email, role, created_at FROM users WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
    return array_values(array_filter(
        $rows,
        static fn ($u) => (int) $u['id'] !== $excludeId && getSiteOwnerId((int) $u['id']) === $ownerId
    ));
}


/** Users created by a given admin (their editors). */
function getUsersCreatedBy(int $adminId): array
{
    $stmt = getDb()->prepare('SELECT id, name, email, role, created_at FROM users WHERE created_by = :id ORDER BY created_at DESC');
    $stmt->execute(['id' => $adminId]);
    return $stmt->fetchAll();
}
