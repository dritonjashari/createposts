<?php


// ===========================================================================
// SMTP email + publish-failure notifications
// ===========================================================================

/**
 * Send an HTML email via SMTP using PHP streams (no external library).
 * Reads SMTP credentials from app_settings for $ownerId.
 */
function sendSmtpEmail(string $to, string $subject, string $htmlBody, int $ownerId): bool
{
    $host     = (string) getSetting($ownerId, 'smtp_host', '');
    $port     = (int)    (getSetting($ownerId, 'smtp_port', '587') ?: '587');
    $user     = (string) getSetting($ownerId, 'smtp_user', '');
    $pass     = (string) getSetting($ownerId, 'smtp_pass', '');
    $from     = (string) getSetting($ownerId, 'smtp_from', '');
    $fromName = (string) getSetting($ownerId, 'smtp_from_name', 'CreatePosts');

    if ($host === '' || $from === '' || $to === '') {
        return false; // SMTP not configured
    }

    $useSsl  = ($port === 465);
    $address = ($useSsl ? 'ssl://' : '') . $host . ':' . $port;

    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($address, $errno, $errstr, 15);
    if (!$sock) {
        return false;
    }
    stream_set_timeout($sock, 15);

    $read = static function () use ($sock): string {
        $buf = '';
        while (!feof($sock)) {
            $line = (string) fgets($sock, 512);
            $buf .= $line;
            // The final line of a multi-line response has a space at position 3.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $buf;
    };

    $send = static function (string $data) use ($sock): void {
        fwrite($sock, $data . "\r\n");
    };

    $read(); // server greeting

    $helo = gethostname() ?: 'localhost';
    $send('EHLO ' . $helo);
    $read();

    if (!$useSsl) {
        $send('STARTTLS');
        $read();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO ' . $helo);
        $read();
    }

    if ($user !== '' && $pass !== '') {
        $send('AUTH LOGIN');
        $read();
        $send(base64_encode($user));
        $read();
        $send(base64_encode($pass));
        $authResp = $read();
        if (strncmp($authResp, '235', 3) !== 0) {
            fclose($sock);
            return false;
        }
    }

    $send('MAIL FROM:<' . $from . '>');
    $read();
    $send('RCPT TO:<' . $to . '>');
    $read();
    $send('DATA');
    $read(); // 354 Start mail input

    $msgId   = bin2hex(random_bytes(8)) . '@createposts';
    $headers = implode("\r\n", [
        'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Message-ID: <' . $msgId . '>',
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);

    $encodedBody = chunk_split(base64_encode($htmlBody));
    fwrite($sock, $headers . "\r\n\r\n" . $encodedBody . "\r\n.\r\n");
    $resp = $read(); // 250 OK

    $send('QUIT');
    @fclose($sock);

    return strncmp($resp, '2', 1) === 0;
}


/**
 * Send a failure alert email after a publish job finishes (or crashes).
 * Silently does nothing if SMTP is not configured or there are no failures.
 */
function sendPublishFailureAlert(int $ownerId, array $job, array $results): void
{
    $notifyEmail = (string) getSetting($ownerId, 'smtp_notify_email', '');
    if ($notifyEmail === '') {
        $stmt = getDb()->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $ownerId]);
        $notifyEmail = (string) ($stmt->fetchColumn() ?: '');
    }
    if ($notifyEmail === '') return;

    // Check for a catastrophic worker error stored under the '_error' key.
    if (isset($results['_error'])) {
        $errMsg  = htmlspecialchars((string) $results['_error']);
        $postTitle = htmlspecialchars((string) ($job['title'] ?? ''));
        $jobId   = (int) ($job['id'] ?? 0);
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:sans-serif;color:#222;'>
<div style='max-width:600px;margin:0 auto;padding:24px;'>
  <h2 style='color:#c0392b;margin:0 0 8px;'>Publish Job Failed</h2>
  <p style='margin:0 0 12px;color:#555;'>Job #{$jobId} encountered a fatal error and could not complete.</p>
  <p style='margin:0 0 12px;'><strong>Post title:</strong> {$postTitle}</p>
  <p style='margin:0 0 20px;background:#fff5f5;border:1px solid #fcc;padding:12px;border-radius:6px;color:#c0392b;'>{$errMsg}</p>
  <p style='margin:0;font-size:12px;color:#aaa;'>Sent by CreatePosts</p>
</div></body></html>";
        $subject = "Publish job #{$jobId} failed: \"{$job['title']}\"";
        @sendSmtpEmail($notifyEmail, $subject, $html, $ownerId);
        return;
    }

    // Collect per-site failures from normal job results.
    $failures = [];
    foreach ($results as $siteId => $r) {
        if (is_array($r) && empty($r['success']) && !empty($r['message'])) {
            $failures[] = ['name' => (string) ($r['name'] ?? "Site #{$siteId}"), 'message' => (string) $r['message']];
        }
    }
    if (empty($failures)) return;

    $failCount = count($failures);
    $total     = (int) ($job['total'] ?? 0);
    $postTitle = htmlspecialchars((string) ($job['title'] ?? ''));
    $jobId     = (int) ($job['id'] ?? 0);

    $rows = '';
    foreach ($failures as $f) {
        $name = htmlspecialchars($f['name']);
        $msg  = htmlspecialchars($f['message']);
        $rows .= "<tr>
          <td style='padding:10px 12px;border-bottom:1px solid #eee;font-weight:500;'>{$name}</td>
          <td style='padding:10px 12px;border-bottom:1px solid #eee;color:#c0392b;'>{$msg}</td>
        </tr>";
    }

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='font-family:sans-serif;color:#222;'>
<div style='max-width:600px;margin:0 auto;padding:24px;'>
  <h2 style='color:#c0392b;margin:0 0 8px;'>Publish Failure Alert</h2>
  <p style='margin:0 0 12px;color:#555;'>Job #{$jobId} — <strong>{$failCount}</strong> of <strong>{$total}</strong> site(s) failed.</p>
  <p style='margin:0 0 16px;'><strong>Post title:</strong> {$postTitle}</p>
  <table style='width:100%;border-collapse:collapse;font-size:14px;'>
    <thead>
      <tr style='background:#f5f5f5;'>
        <th style='padding:10px 12px;text-align:left;border-bottom:2px solid #ddd;'>Site</th>
        <th style='padding:10px 12px;text-align:left;border-bottom:2px solid #ddd;'>Error</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>
  <p style='margin:20px 0 0;font-size:12px;color:#aaa;'>Sent by CreatePosts</p>
</div></body></html>";

    $subject = "Publish failure: \"{$job['title']}\" ({$failCount}/{$total} sites failed)";
    @sendSmtpEmail($notifyEmail, $subject, $html, $ownerId);
}
