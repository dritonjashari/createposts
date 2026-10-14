<?php
// Own profile. Mirrors profile.php.

function profile_getAction(): void
{
    requireLoginApi();
    jsonResponse(['user' => currentUser()]);
}

function profile_updateAction(): void
{
    requireLoginApi();
    $body   = jsonBody();
    $result = updateUserProfile(currentUserId(), (string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''));
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message'], 'user' => currentUser()]);
}

function profile_changePasswordAction(): void
{
    requireLoginApi();
    $body   = jsonBody();
    $result = changeUserPassword(
        currentUserId(),
        (string) ($body['current_password'] ?? ''),
        (string) ($body['new_password'] ?? ''),
        (string) ($body['confirm_password'] ?? '')
    );
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    jsonResponse(['message' => $result['message']]);
}
