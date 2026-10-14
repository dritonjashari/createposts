<?php


// ---------------------------------------------------------------------------
// Google Analytics 4 — realtime active users via the Data API (service account)
// ---------------------------------------------------------------------------
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}


/**
 * Get a Google OAuth2 access token from a service-account JSON (cached in session).
 * Returns the token, or null on failure (with $error set).
 */
function getGoogleAccessToken(string $saJson, string &$error = ''): ?string
{
    if (!empty($_SESSION['ga_token']) && !empty($_SESSION['ga_token_exp']) && $_SESSION['ga_token_exp'] > time() + 60) {
        return $_SESSION['ga_token'];
    }

    $sa = json_decode($saJson, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        $error = 'The service account JSON is missing or invalid.';
        return null;
    }

    $now    = time();
    $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $unsigned = base64UrlEncode(json_encode($header)) . '.' . base64UrlEncode(json_encode($claims));
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        $error = 'Could not sign the request (bad private key).';
        return null;
    }
    $jwt = $unsigned . '.' . base64UrlEncode($signature);

    $ch = curl_init($tokenUri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($code === 200 && !empty($data['access_token'])) {
        $_SESSION['ga_token']     = $data['access_token'];
        $_SESSION['ga_token_exp'] = $now + (int) ($data['expires_in'] ?? 3600);
        return $data['access_token'];
    }

    $error = 'Google rejected the service account: ' . (is_array($data) ? json_encode($data['error_description'] ?? $data) : $raw);
    return null;
}


/** Realtime active users for a GA4 property. Returns int (0 = none) or null on error. */
function gaRealtimeActiveUsers(string $accessToken, string $propertyId): ?int
{
    $propertyId = preg_replace('/[^0-9]/', '', $propertyId);
    if ($propertyId === '') {
        return null;
    }

    $ch = curl_init('https://analyticsdata.googleapis.com/v1beta/properties/' . $propertyId . ':runRealtimeReport');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['metrics' => [['name' => 'activeUsers']]]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        return null;
    }
    $data = json_decode($raw, true);
    return (int) ($data['rows'][0]['metricValues'][0]['value'] ?? 0);
}


/** Save a site's GA4 numeric property id (admin-owned site). */
function setSiteGaPropertyId(int $siteId, int $userId, string $propertyId): void
{
    $propertyId = preg_replace('/[^0-9]/', '', $propertyId);
    $stmt = getDb()->prepare('UPDATE wordpress_sites SET ga_property_id = :p WHERE id = :id AND user_id = :u');
    $stmt->execute(['p' => $propertyId !== '' ? $propertyId : null, 'id' => $siteId, 'u' => $userId]);
}
