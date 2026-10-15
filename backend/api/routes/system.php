<?php
// System Settings. Mirrors system.php — admin-only.

function system_getAction(): void
{
    requireAdminApi();
    // Keys, settings and timeslots belong to the team owner (shared by every
    // admin of the team); only the active workspace is each admin's own pick.
    $selfId     = currentUserId();
    $userId     = getSiteOwnerId($selfId);
    // Nearly fresh: a workspace just created in Publer should be here to set up.
    $workspaces = getPublerWorkspaces($selfId, 10);

    $activeWorkspace = fetchPublerWorkspaceId($selfId);

    // One-time migration: the old single global "first page" becomes per-workspace,
    // attributed to whichever workspace was active when it was set.
    $legacyFirstPage = getSetting($userId, 'first_page_id', null);
    if ($legacyFirstPage !== null) {
        if ($legacyFirstPage !== '' && $activeWorkspace !== '' && getSetting($userId, 'first_page_id_' . $activeWorkspace, '') === '') {
            setSetting($userId, 'first_page_id_' . $activeWorkspace, $legacyFirstPage);
        }
        getDb()->prepare("DELETE FROM app_settings WHERE user_id = :u AND setting_key = 'first_page_id'")->execute(['u' => $userId]);
    }

    $pagesByWorkspace     = [];
    $firstPageByWorkspace = [];
    foreach ($workspaces as $w) {
        $pagesByWorkspace[$w['id']]     = fetchPublerFacebookPages($selfId, $w['id']);
        $firstPageByWorkspace[$w['id']] = (string) getSetting($userId, 'first_page_id_' . $w['id'], '');
    }

    $stmt = getDb()->prepare('SELECT openai_api_key, openai_model FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $openaiSettings = $stmt->fetch() ?: [];
    $priceIn  = getSetting($userId, 'openai_price_in', '');
    $priceOut = getSetting($userId, 'openai_price_out', '');
    [$effIn, $effOut] = getModelPricing($userId, $openaiSettings['openai_model'] ?? 'gpt-4o-mini');

    $stmt = getDb()->prepare('SELECT publer_api_key FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $publerApiKey = (string) ($stmt->fetchColumn() ?: '');

    jsonResponse([
        'workspaces'               => $workspaces,
        'active_workspace_id'      => $activeWorkspace,
        'timezone'                 => getAppTimezone($userId),
        'gap_value'                => (int) getSetting($userId, 'gap_value', '0'),
        'gap_unit'                 => getSetting($userId, 'gap_unit', 'minutes'),
        'default_use_mapped'       => getSetting($userId, 'default_use_mapped', '1') === '1',
        'mapped_link_target'       => getSetting($userId, 'mapped_link_target', 'comment1'),
        'default_paraphrase_social' => getSetting($userId, 'default_paraphrase_social', '0') === '1',
        'default_paraphrase_comment' => getSetting($userId, 'default_paraphrase_comment', '1') === '1',
        'default_paraphrase_comment2' => getSetting($userId, 'default_paraphrase_comment2', '1') === '1',
        'comment_delay_minutes'    => (int) getSetting($userId, 'comment_delay_minutes', '0'),
        'comment2_delay_minutes'   => (int) getSetting($userId, 'comment2_delay_minutes', '0'),
        'default_paraphrase_web'   => getSetting($userId, 'default_paraphrase_web', '0') === '1',
        'image_format'             => (string) getSetting($userId, 'image_format', ''),
        'auto_clean_text'          => getSetting($userId, 'auto_clean_text', '0') === '1',
        'editors_can_schedule'     => getSetting($userId, 'editors_can_schedule', '1') === '1',
        'auto_delete_enabled'      => getSetting($userId, 'auto_delete_enabled', '0') === '1',
        'auto_delete_hours'        => max(1, (int) getSetting($userId, 'auto_delete_hours', '24')),
        'default_add_pixels'       => defaultAddPixels($userId),
        'timeslots'                => getTimeslots($userId),
        'pages_by_workspace'       => $pagesByWorkspace,
        'first_page_by_workspace'  => $firstPageByWorkspace,
        'openai' => [
            'api_key'              => $openaiSettings['openai_api_key'] ?? '',
            'model'                => $openaiSettings['openai_model'] ?? 'gpt-4o-mini',
            'price_in'             => $priceIn,
            'price_out'            => $priceOut,
            'effective_price_in'   => $effIn,
            'effective_price_out'  => $effOut,
            'spend'                => getOpenAiSpend($userId),
        ],
        'publer_api_key' => $publerApiKey,
        'smtp' => [
            'host'         => (string) getSetting($userId, 'smtp_host', ''),
            'port'         => (string) getSetting($userId, 'smtp_port', '587'),
            'user'         => (string) getSetting($userId, 'smtp_user', ''),
            // Never sent back to the browser — only whether one is saved. An
            // empty password on save keeps the stored one (see saveSmtp).
            'pass'         => '',
            'pass_set'     => (string) getSetting($userId, 'smtp_pass', '') !== '',
            'from'         => (string) getSetting($userId, 'smtp_from', ''),
            'from_name'    => (string) getSetting($userId, 'smtp_from_name', 'CreatePosts'),
            'notify_email' => (string) getSetting($userId, 'smtp_notify_email', ''),
        ],
    ]);
}

function system_saveSmtpAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();

    setSetting($userId, 'smtp_host',         trim((string) ($body['host']         ?? '')));
    setSetting($userId, 'smtp_port',         trim((string) ($body['port']         ?? '587')));
    setSetting($userId, 'smtp_user',         trim((string) ($body['user']         ?? '')));
    setSetting($userId, 'smtp_from',         trim((string) ($body['from']         ?? '')));
    setSetting($userId, 'smtp_from_name',    trim((string) ($body['from_name']    ?? 'CreatePosts')));
    setSetting($userId, 'smtp_notify_email', trim((string) ($body['notify_email'] ?? '')));

    // Only overwrite the password if a new one was actually submitted.
    $pass = trim((string) ($body['pass'] ?? ''));
    if ($pass !== '') {
        setSetting($userId, 'smtp_pass', $pass);
    }

    jsonResponse(['message' => 'SMTP settings saved.']);
}

function system_saveSchedulingAction(): void
{
    requireAdminApi();
    $userId     = getSiteOwnerId(currentUserId());
    $workspaces = getPublerWorkspaces(currentUserId());
    $body       = jsonBody();

    $gapValue = max(0, (int) ($body['gap_value'] ?? 0));
    $gapUnit  = (($body['gap_unit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';

    $allowedFormats = ['', '1:1', '4:5', '9:16', '16:9'];
    $imageFormat    = in_array($body['image_format'] ?? '', $allowedFormats, true) ? ($body['image_format'] ?? '') : '';

    $timezone = trim((string) ($body['timezone'] ?? ''));
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $timezone = 'Europe/Berlin';
    }
    setSetting($userId, 'timezone', $timezone);
    setSetting($userId, 'gap_value', (string) $gapValue);
    setSetting($userId, 'gap_unit', $gapUnit);

    foreach ($workspaces as $w) {
        $pid = trim((string) ($body['first_page_id'][$w['id']] ?? ''));
        setSetting($userId, 'first_page_id_' . $w['id'], $pid);
    }
    setSetting($userId, 'default_use_mapped', !empty($body['default_use_mapped']) ? '1' : '0');
    $mappedLinkTarget = ($body['mapped_link_target'] ?? 'comment1') === 'comment2' ? 'comment2' : 'comment1';
    setSetting($userId, 'mapped_link_target', $mappedLinkTarget);
    setSetting($userId, 'default_paraphrase_social', !empty($body['default_paraphrase_social']) ? '1' : '0');
    setSetting($userId, 'default_paraphrase_comment', !empty($body['default_paraphrase_comment']) ? '1' : '0');
    setSetting($userId, 'default_paraphrase_comment2', !empty($body['default_paraphrase_comment2']) ? '1' : '0');
    setSetting($userId, 'comment_delay_minutes', (string) max(0, (int) ($body['comment_delay_minutes'] ?? 0)));
    setSetting($userId, 'comment2_delay_minutes', (string) max(0, (int) ($body['comment2_delay_minutes'] ?? 0)));
    setSetting($userId, 'default_paraphrase_web', !empty($body['default_paraphrase_web']) ? '1' : '0');
    setSetting($userId, 'image_format', $imageFormat);
    setSetting($userId, 'auto_clean_text', !empty($body['auto_clean_text']) ? '1' : '0');
    setSetting($userId, 'editors_can_schedule', !empty($body['editors_can_schedule']) ? '1' : '0');
    setSetting($userId, 'auto_delete_enabled', !empty($body['auto_delete_enabled']) ? '1' : '0');
    setSetting($userId, 'auto_delete_hours', (string) min(AUTO_DELETE_MAX_HOURS, max(1, (int) ($body['auto_delete_hours'] ?? 24))));
    if (array_key_exists('default_add_pixels', $body)) {
        setSetting($userId, 'default_add_pixels', !empty($body['default_add_pixels']) ? '1' : '0');
    }

    jsonResponse(['message' => 'System settings saved.']);
}

function system_saveWorkspaceAction(): void
{
    requireAdminApi();
    $body = jsonBody();
    if (!setActiveWorkspaceId(currentUserId(), trim((string) ($body['workspace_id'] ?? '')))) {
        jsonError(422, 'That workspace is no longer in your Publer account. Reload the page and try again.');
    }
    jsonResponse(['message' => 'Active Publer workspace updated.']);
}

function system_addTimeslotAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();
    $t      = trim((string) ($body['time'] ?? ''));
    $ws     = trim((string) ($body['workspace_id'] ?? ''));
    $days   = [];
    foreach ((array) ($body['days'] ?? []) as $d) {
        $d = (int) $d;
        if ($d >= 0 && $d <= 6) {
            $days[] = $d;
        }
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t)) {
        jsonError(422, 'Please enter a valid time (HH:MM).');
    }
    if (!addTimeslot($userId, $ws, $t, $days)) {
        jsonError(409, 'That timeslot already exists.');
    }
    jsonResponse(['message' => 'Timeslot added.', 'timeslots' => getTimeslots($userId)], 201);
}

function system_removeTimeslotAction(string $id): void
{
    requireAdminApi();
    if (!removeTimeslot(getSiteOwnerId(currentUserId()), (int) $id)) {
        jsonError(404, 'Timeslot not found.');
    }
    jsonResponse(['message' => 'Timeslot removed.']);
}

function system_savePublerKeyAction(): void
{
    requireAdminApi();
    $selfId    = currentUserId();
    $userId    = getSiteOwnerId($selfId);
    $body      = jsonBody();
    $publerKey = trim((string) ($body['publer_api_key'] ?? ''));

    $stmt = getDb()->prepare('UPDATE users SET publer_api_key = :key WHERE id = :id');
    $stmt->execute(['key' => $publerKey !== '' ? $publerKey : null, 'id' => $userId]);
    foreach (array_unique([$selfId, $userId]) as $id) {
        unset($_SESSION['publer_ws_first_' . $id]); // force workspace re-resolution with the new key
        foreach (array_keys($_SESSION) as $k) { // drop cached Facebook pages (any workspace) — new key may differ
            if (str_starts_with($k, 'publer_fb_pages_' . $id . '_')) {
                unset($_SESSION[$k]);
            }
        }
        setSetting($id, 'publer_workspace_id', ''); // reset selection; new key may have different workspaces
    }

    if ($publerKey === '') {
        jsonResponse(['message' => 'Publer API key cleared. The app default key (if any) will be used instead.', 'status' => 'warning']);
    }

    $valid = publerApiKeyValid($publerKey);
    if ($valid === true) {
        jsonResponse(['message' => 'Publer API key saved and verified.', 'status' => 'success']);
    } elseif ($valid === false) {
        jsonResponse(['message' => 'Saved, but Publer rejected this API key. Double-check it in your Publer account.', 'status' => 'error']);
    } else {
        jsonResponse(['message' => 'Saved, but the API key could not be verified with Publer right now.', 'status' => 'warning']);
    }
}

function system_saveOpenAiAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();
    $apiKey = trim((string) ($body['api_key'] ?? ''));
    $model  = trim((string) ($body['model'] ?? 'gpt-4o-mini'));
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $stmt = getDb()->prepare('UPDATE users SET openai_api_key = :api_key, openai_model = :model WHERE id = :id');
    $stmt->execute(['api_key' => $apiKey, 'model' => $model, 'id' => $userId]);

    setSetting($userId, 'openai_price_in', (string) max(0, (float) ($body['price_in'] ?? 0)));
    setSetting($userId, 'openai_price_out', (string) max(0, (float) ($body['price_out'] ?? 0)));

    if ($apiKey === '') {
        jsonResponse(['message' => 'OpenAI settings saved. Add an API key to use paraphrasing.', 'status' => 'warning']);
    }

    $exists = openAiModelExists($apiKey, $model);
    if ($exists === true) {
        jsonResponse(['message' => 'OpenAI settings saved and the model "' . $model . '" was verified.', 'status' => 'success']);
    } elseif ($exists === false) {
        jsonResponse(['message' => 'Saved, but the model "' . $model . '" was not found. Use a valid id such as gpt-4o-mini.', 'status' => 'error']);
    } else {
        jsonResponse(['message' => 'Saved, but the API key or model could not be verified with OpenAI.', 'status' => 'warning']);
    }
}
