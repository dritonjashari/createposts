<?php


// ---------------------------------------------------------------------------
// Tasks (admin assigns tasks to the users they created; assignee marks them
// finished; either side can leave notes on a task).
// ---------------------------------------------------------------------------
function ensureTasksTables(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db = getDb();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            assigned_to INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            due_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            CONSTRAINT fk_task_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_task_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS task_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            user_id INT NOT NULL,
            note TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_note_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            CONSTRAINT fk_note_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS task_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NOT NULL,
            url VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_link_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ensured = true;
}


/** Add "http://" to a bare link so it's a clickable absolute URL; blank in, blank out. */
function normalizeTaskLink(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }
    return $url;
}


/**
 * Create a task with its list of links. Assignment is restricted to users
 * this admin actually created. $links may contain blank entries (from empty
 * form rows) — those are dropped.
 */
function createTask(int $adminId, int $assignedTo, string $title, string $description, ?string $dueDate, array $links = []): array
{
    ensureTasksTables();
    $title = trim($title);
    if ($title === '') {
        return ['success' => false, 'message' => 'Task title is required.'];
    }

    if ($assignedTo === $adminId || !userExists($assignedTo) || getSiteOwnerId($assignedTo) !== getSiteOwnerId($adminId)) {
        return ['success' => false, 'message' => 'You can only assign tasks to members of your team.'];
    }

    $due = null;
    if ($dueDate !== null && trim($dueDate) !== '') {
        $d = DateTime::createFromFormat('Y-m-d', trim($dueDate));
        $due = $d ? $d->format('Y-m-d') : null;
    }

    $cleanLinks = array_values(array_unique(array_filter(array_map(
        static fn ($u) => normalizeTaskLink((string) $u),
        $links
    ), static fn ($u) => $u !== '')));

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO tasks (admin_id, assigned_to, title, description, due_date)
         VALUES (:admin_id, :assigned_to, :title, :description, :due_date)'
    );
    $stmt->execute([
        'admin_id'    => $adminId,
        'assigned_to' => $assignedTo,
        'title'       => $title,
        'description' => trim($description) !== '' ? trim($description) : null,
        'due_date'    => $due,
    ]);
    $taskId = (int) $db->lastInsertId();

    if (!empty($cleanLinks)) {
        $linkStmt = $db->prepare('INSERT INTO task_links (task_id, url) VALUES (:task_id, :url)');
        foreach ($cleanLinks as $url) {
            $linkStmt->execute(['task_id' => $taskId, 'url' => $url]);
        }
    }

    createNotification($assignedTo, 'New task assigned: "' . $title . '"', $taskId);

    return ['success' => true, 'message' => 'Task created.'];
}


/** All links attached to a task, in the order they were added. */
function getTaskLinks(int $taskId): array
{
    ensureTasksTables();
    $stmt = getDb()->prepare('SELECT * FROM task_links WHERE task_id = :task_id ORDER BY id ASC');
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll();
}


/** All tasks an admin created, newest/most-urgent first, with the assignee's name, note and link counts. */
function getTasksForAdmin(int $adminId): array
{
    ensureTasksTables();
    $stmt = getDb()->prepare(
        "SELECT t.*, u.name AS assignee_name,
                (SELECT COUNT(*) FROM task_notes n WHERE n.task_id = t.id) AS note_count,
                (SELECT COUNT(*) FROM task_links l WHERE l.task_id = t.id) AS link_count
         FROM tasks t
         JOIN users u ON u.id = t.assigned_to
         WHERE t.admin_id = :admin_id
         ORDER BY (t.status = 'done') ASC, (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at DESC"
    );
    $stmt->execute(['admin_id' => $adminId]);
    return $stmt->fetchAll();
}


/** All tasks assigned to a user, with the assigning admin's name, note and link counts. */
function getTasksForAssignee(int $userId): array
{
    ensureTasksTables();
    $stmt = getDb()->prepare(
        "SELECT t.*, u.name AS admin_name,
                (SELECT COUNT(*) FROM task_notes n WHERE n.task_id = t.id) AS note_count,
                (SELECT COUNT(*) FROM task_links l WHERE l.task_id = t.id) AS link_count
         FROM tasks t
         JOIN users u ON u.id = t.admin_id
         WHERE t.assigned_to = :user_id
         ORDER BY (t.status = 'done') ASC, (t.due_date IS NULL) ASC, t.due_date ASC, t.created_at DESC"
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}


/** Tasks this admin marked done most recently, with who finished them — powers the dashboard widget. */
function getRecentlyFinishedTasks(int $adminId, int $limit = 8): array
{
    ensureTasksTables();
    $stmt = getDb()->prepare(
        "SELECT t.*, u.name AS assignee_name
         FROM tasks t
         JOIN users u ON u.id = t.assigned_to
         WHERE t.admin_id = :admin_id AND t.status = 'done'
         ORDER BY t.completed_at DESC
         LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute(['admin_id' => $adminId]);
    return $stmt->fetchAll();
}


function getTaskById(int $taskId): ?array
{
    ensureTasksTables();
    $stmt = getDb()->prepare('SELECT * FROM tasks WHERE id = :id');
    $stmt->execute(['id' => $taskId]);
    return $stmt->fetch() ?: null;
}


/** A single task with both the admin's and the assignee's name resolved (for detail views). */
function getTaskDetail(int $taskId): ?array
{
    ensureTasksTables();
    $stmt = getDb()->prepare(
        'SELECT t.*, a.name AS admin_name, e.name AS assignee_name
         FROM tasks t
         JOIN users a ON a.id = t.admin_id
         JOIN users e ON e.id = t.assigned_to
         WHERE t.id = :id'
    );
    $stmt->execute(['id' => $taskId]);
    return $stmt->fetch() ?: null;
}


/** Only the task's creating admin or its assignee may view/act on it. */
function taskUserCanAccess(array $task, int $userId): bool
{
    return (int) $task['admin_id'] === $userId || (int) $task['assigned_to'] === $userId;
}


function setTaskStatus(int $taskId, string $status): void
{
    $status = $status === 'done' ? 'done' : 'open';
    $stmt = getDb()->prepare(
        "UPDATE tasks SET status = :status, completed_at = " . ($status === 'done' ? 'CURRENT_TIMESTAMP' : 'NULL') . " WHERE id = :id"
    );
    $stmt->execute(['status' => $status, 'id' => $taskId]);
}


/** Only the assigning admin may delete a task (notes/links cascade via FK). */
function deleteTask(int $taskId, int $adminId): bool
{
    $stmt = getDb()->prepare('DELETE FROM tasks WHERE id = :id AND admin_id = :admin_id');
    $stmt->execute(['id' => $taskId, 'admin_id' => $adminId]);
    return $stmt->rowCount() > 0;
}


function addTaskNote(int $taskId, int $userId, string $note): bool
{
    ensureTasksTables();
    $note = trim($note);
    if ($note === '') {
        return false;
    }
    $stmt = getDb()->prepare('INSERT INTO task_notes (task_id, user_id, note) VALUES (:task_id, :user_id, :note)');
    $stmt->execute(['task_id' => $taskId, 'user_id' => $userId, 'note' => $note]);
    return true;
}


/** All notes on a task, oldest first, with each author's name. */
function getTaskNotes(int $taskId): array
{
    ensureTasksTables();
    $stmt = getDb()->prepare(
        'SELECT tn.*, u.name AS author_name
         FROM task_notes tn
         JOIN users u ON u.id = tn.user_id
         WHERE tn.task_id = :task_id
         ORDER BY tn.created_at ASC'
    );
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll();
}
