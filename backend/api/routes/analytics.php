<?php
// Realtime Analytics (Google Analytics 4). Mirrors analytics.php — admin-only.

function analytics_getAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $sites  = getWordPressSitesForUser($userId);

    $saJson  = (string) getSetting($userId, 'ga_service_account', '');
    $saEmail = '';
    if ($saJson !== '') {
        $sa      = json_decode($saJson, true);
        $saEmail = is_array($sa) ? ($sa['client_email'] ?? '') : '';
    }

    $tokenError  = '';
    $accessToken = $saJson !== '' ? getGoogleAccessToken($saJson, $tokenError) : null;

    $realtime     = [];
    $totalOnline  = 0;
    $haveAnyCount = false;
    foreach ($sites as $site) {
        $propId = trim((string) ($site['ga_property_id'] ?? ''));
        if ($accessToken && $propId !== '') {
            $count = gaRealtimeActiveUsers($accessToken, $propId);
            $realtime[$site['id']] = $count;
            if ($count !== null) {
                $totalOnline += $count;
                $haveAnyCount = true;
            }
        } else {
            $realtime[$site['id']] = null;
        }
    }

    jsonResponse([
        'sites'                 => $sites,
        'realtime'              => $realtime,
        'total_online'          => $haveAnyCount ? $totalOnline : null,
        'service_account_email' => $saEmail,
        'has_service_account'   => $saJson !== '',
        'token_error'           => ($saJson !== '' && $accessToken === null) ? ($tokenError ?: 'Could not authenticate with Google.') : null,
    ]);
}

function analytics_saveServiceAccountAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();
    $json   = trim((string) ($body['service_account_json'] ?? ''));
    $sa     = json_decode($json, true);

    if ($json !== '' && (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']))) {
        jsonError(422, 'That does not look like a valid service account JSON (missing client_email / private_key).');
    }

    setSetting($userId, 'ga_service_account', $json);
    unset($_SESSION['ga_token'], $_SESSION['ga_token_exp']); // force re-auth
    jsonResponse(['message' => $json === '' ? 'Service account cleared.' : 'Service account saved.']);
}

function analytics_savePropertiesAction(): void
{
    requireAdminApi();
    $userId = getSiteOwnerId(currentUserId());
    $body   = jsonBody();
    foreach ((array) ($body['property'] ?? []) as $siteId => $propId) {
        setSiteGaPropertyId((int) $siteId, $userId, (string) $propId);
    }
    jsonResponse(['message' => 'Property IDs saved.']);
}
