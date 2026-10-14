<?php
function auth_meAction(): void
{
    requireLoginApi();
    jsonResponse(['user' => currentUser()]);
}

function auth_loginAction(): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    $result = loginUser($email, $password);
    if (!$result['success']) {
        jsonError(401, $result['message']);
    }

    // bootstrap.php already called session_start() above with a fresh,
    // auto-generated id (no incoming Authorization header on a login
    // request) — that id IS the bearer token the frontend should send back.
    jsonResponse([
        'token' => session_id(),
        'user'  => currentUser(),
    ]);
}

function auth_registerAction(): void
{
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $confirm = (string) ($body['confirm_password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        jsonError(422, 'Name, email and password are required.');
    }
    if ($password !== $confirm) {
        jsonError(422, 'Passwords do not match.');
    }

    $result = registerUser($name, $email, $password);
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }

    jsonResponse(['message' => $result['message']], 201);
}

function auth_logoutAction(): void
{
    requireLoginApi();
    cpSessionReopen(); // bootstrap closes the session early; destroy needs it active
    session_destroy();
    cpSessionForget();
    jsonResponse(['message' => 'Logged out.']);
}
