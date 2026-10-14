<?php
// Tasks. Mirrors tasks.php/task.php — admin assigns tasks (with links) to
// users they created; the assignee (or the assigning admin) can toggle
// status and add notes. The list only carries counts; full details (links,
// notes) are fetched per-task via tasks_getAction.

function tasks_listAction(): void
{
    requireLoginApi();
    $userId      = currentUserId();
    $isAdminUser = isAdmin();

    jsonResponse([
        'tasks'            => $isAdminUser ? getTasksForAdmin($userId) : getTasksForAssignee($userId),
        'assignable_users' => $isAdminUser ? getTeamMembers(getSiteOwnerId($userId), $userId) : [],
        'is_admin'         => $isAdminUser,
    ]);
}

function tasks_getAction(string $id): void
{
    requireLoginApi();
    $taskId = (int) $id;
    $task   = getTaskDetail($taskId);
    if (!$task || !taskUserCanAccess($task, currentUserId())) {
        jsonError(404, 'Task not found.');
    }

    $task['links'] = getTaskLinks($taskId);
    $task['notes'] = getTaskNotes($taskId);
    jsonResponse(['task' => $task, 'is_admin' => isAdmin()]);
}

function tasks_createAction(): void
{
    requireAdminApi();
    $body   = jsonBody();
    $links  = array_map('strval', (array) ($body['links'] ?? []));
    $result = createTask(
        currentUserId(),
        (int) ($body['assigned_to'] ?? 0),
        (string) ($body['title'] ?? ''),
        (string) ($body['description'] ?? ''),
        isset($body['due_date']) ? (string) $body['due_date'] : null,
        $links
    );
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']], 201);
}

function tasks_setStatusAction(string $id): void
{
    requireLoginApi();
    $taskId = (int) $id;
    $task   = getTaskById($taskId);
    if (!$task || !taskUserCanAccess($task, currentUserId())) {
        jsonError(404, 'Task not found.');
    }

    $body   = jsonBody();
    $status = ($body['status'] ?? '') === 'done' ? 'done' : 'open';
    setTaskStatus($taskId, $status);
    jsonResponse(['message' => $status === 'done' ? 'Task marked as finished.' : 'Task reopened.']);
}

function tasks_deleteAction(string $id): void
{
    requireAdminApi();
    if (!deleteTask((int) $id, currentUserId())) {
        jsonError(404, 'Task not found.');
    }
    jsonResponse(['message' => 'Task deleted.']);
}

function tasks_addNoteAction(string $id): void
{
    requireLoginApi();
    $taskId = (int) $id;
    $task   = getTaskById($taskId);
    if (!$task || !taskUserCanAccess($task, currentUserId())) {
        jsonError(404, 'Task not found.');
    }

    $body = jsonBody();
    if (!addTaskNote($taskId, currentUserId(), (string) ($body['note'] ?? ''))) {
        jsonError(422, 'Note cannot be empty.');
    }
    jsonResponse(['message' => 'Note added.', 'notes' => getTaskNotes($taskId)], 201);
}
