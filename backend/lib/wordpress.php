<?php


function normalizeSiteUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        // Default to https, not http: WordPress Application Passwords (what
        // this app authenticates with) are refused over a plain-HTTP
        // connection, so defaulting to http:// here would make a perfectly
        // valid site/subdomain fail with a misleading "authentication
        // failed" error even though the credentials are correct.
        $url = 'https://' . $url;
    }

    return rtrim($url, '/');
}


/** Older installs predate the rest_style column — add it once per process. */
function ensureWordPressSitesRestStyleColumn(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        getDb()->exec("ALTER TABLE wordpress_sites ADD COLUMN rest_style VARCHAR(10) NOT NULL DEFAULT 'pretty' AFTER site_url");
    } catch (Throwable $e) {
        // Column already exists — safe to ignore.
    }
    $ensured = true;
}


/**
 * Build the correct WordPress REST API URL for a site, honoring whether it
 * uses pretty permalinks (/wp-json/...) or needs the query-string fallback
 * (/index.php?rest_route=/...). Some hosts/installs — commonly a freshly
 * created subdomain still on the default "Plain" permalink structure — only
 * support the latter; hardcoding /wp-json/ 404s on those sites even with
 * correct credentials, which surfaces as a misleading "authentication
 * failed" error. $pathWithQuery is e.g. "wp/v2/posts" or
 * "wp/v2/posts/123?_fields=link".
 */
function wordPressRestUrl(array $site, string $pathWithQuery): string
{
    $base = rtrim((string) $site['site_url'], '/');
    if ((string) ($site['rest_style'] ?? 'pretty') === 'query') {
        $qPos  = strpos($pathWithQuery, '?');
        $path  = $qPos === false ? $pathWithQuery : substr($pathWithQuery, 0, $qPos);
        $extra = $qPos === false ? '' : substr($pathWithQuery, $qPos + 1);
        return $base . '/index.php?rest_route=/' . ltrim($path, '/') . ($extra !== '' ? '&' . $extra : '');
    }
    return $base . '/wp-json/' . ltrim($pathWithQuery, '/');
}


function addWordPressSite(int $userId, string $siteName, string $siteUrl, string $username, string $applicationPassword): array
{
    ensureWordPressSitesRestStyleColumn();
    $db = getDb();
    $siteUrl = normalizeSiteUrl($siteUrl);

    if ($siteUrl === '') {
        return ['success' => false, 'message' => 'Site URL is required.'];
    }

    $testResult = testWordPressConnection($siteUrl, $username, $applicationPassword);
    if (!$testResult['success']) {
        return ['success' => false, 'message' => $testResult['message']];
    }

    $stmt = $db->prepare('INSERT INTO wordpress_sites (user_id, site_name, site_url, rest_style, username, application_password) VALUES (:user_id, :site_name, :site_url, :rest_style, :username, :application_password)');
    $stmt->execute([
        'user_id' => $userId,
        'site_name' => trim($siteName),
        'site_url' => $siteUrl,
        'rest_style' => $testResult['rest_style'] ?? 'pretty',
        'username' => trim($username),
        'application_password' => trim($applicationPassword),
    ]);

    return ['success' => true, 'message' => 'WordPress site connected successfully.'];
}


/**
 * Tries the pretty-permalink REST URL first, then falls back to the
 * query-string form (?rest_route=) if the first attempt 404s — some WP
 * installs (e.g. a subdomain still on "Plain" permalinks) only support the
 * latter. Returns which style worked so the caller can persist it.
 */
function testWordPressConnection(string $siteUrl, string $username, string $applicationPassword): array
{
    foreach (['pretty', 'query'] as $style) {
        $url = $style === 'pretty'
            ? $siteUrl . '/wp-json/wp/v2/users/me'
            : $siteUrl . '/index.php?rest_route=/wp/v2/users/me';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($username . ':' . $applicationPassword),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            // Network-level failure — the same for either URL style.
            return ['success' => false, 'message' => 'Connection error: ' . $error];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'Connection verified.', 'rest_style' => $style];
        }

        // A 404 means this URL style isn't supported here — try the other
        // one before giving up. Any other code (401/403/...) means the site
        // WAS reached but the credentials are wrong, so retrying with the
        // other style would fail identically — no point trying it.
        if ($httpCode !== 404) {
            return ['success' => false, 'message' => 'WordPress API authentication failed. Check the URL, username, and application password.'];
        }
    }

    return ['success' => false, 'message' => 'WordPress API authentication failed. Check the URL, username, and application password.'];
}


function getWordPressSitesForUser(int $userId): array
{
    ensureWordPressSitesRestStyleColumn();
    $stmt = getDb()->prepare('SELECT * FROM wordpress_sites WHERE user_id = :user_id ORDER BY created_at DESC');
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}


function getWordPressSiteById(int $siteId, int $userId): ?array
{
    ensureWordPressSitesRestStyleColumn();
    $stmt = getDb()->prepare('SELECT * FROM wordpress_sites WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $siteId, 'user_id' => $userId]);
    return $stmt->fetch() ?: null;
}


function deleteWordPressSite(int $siteId, int $userId): bool
{
    $stmt = getDb()->prepare('DELETE FROM wordpress_sites WHERE id = :id AND user_id = :user_id');
    return $stmt->execute(['id' => $siteId, 'user_id' => $userId]);
}


/**
 * Stamps a 4×4 white pixel at a random position so every exported copy has a
 * unique file hash. Returns a path to a NEW temp file — the caller is
 * responsible for unlinking it. Falls back to a plain copy when GD is
 * unavailable or the format is unsupported.
 *
 * $alpha is GD's alpha scale: 0 = fully opaque, 127 = fully transparent
 * (e.g. 13 ≈ 90% opacity, 25 ≈ 80% opacity).
 */
function addUniquenessMarker(string $sourcePath, string $mimeType = '', int $alpha = 13): string
{
    if ($mimeType === '') {
        $mimeType = (string) (function_exists('mime_content_type') ? mime_content_type($sourcePath) : '');
    }

    $extMap = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png'  => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
    ];
    $ext = $extMap[$mimeType] ?? 'jpg';
    $outPath = sys_get_temp_dir() . '/createposts-uniq-' . bin2hex(random_bytes(8)) . '.' . $ext;

    if (!function_exists('imagecreatefromjpeg')) {
        copy($sourcePath, $outPath);
        return $outPath;
    }

    $img = null;
    switch ($mimeType) {
        case 'image/jpeg': case 'image/jpg': $img = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':  $img = @imagecreatefrompng($sourcePath);  break;
        case 'image/gif':  $img = @imagecreatefromgif($sourcePath);  break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($sourcePath);
            break;
    }

    if (!$img) {
        copy($sourcePath, $outPath);
        return $outPath;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $px = random_int(0, max(0, $w - 4));
    $py = random_int(0, max(0, $h - 4));

    imagealphablending($img, true);
    $white = imagecolorallocatealpha($img, 255, 255, 255, max(0, min(127, $alpha)));
    imagefilledrectangle($img, $px, $py, $px + 3, $py + 3, $white);
    imagesavealpha($img, true);

    switch ($mimeType) {
        case 'image/jpeg': case 'image/jpg': imagejpeg($img, $outPath, 92); break;
        case 'image/png':  imagepng($img, $outPath, 6);  break;
        case 'image/gif':  imagegif($img, $outPath);     break;
        case 'image/webp':
            function_exists('imagewebp') ? imagewebp($img, $outPath, 90) : imagejpeg($img, $outPath, 92);
            break;
        default: copy($sourcePath, $outPath);
    }
    imagedestroy($img);
    return $outPath;
}


function uploadFeaturedImageToWordPress(array $site, string $filePath, ?string $originalName = null): array
{
    if (!is_file($filePath)) {
        return ['success' => false, 'message' => 'Featured image file was not found.'];
    }

    $mimeType = mime_content_type($filePath) ?: 'image/jpeg';
    $extension = 'jpg';

    if ($originalName !== null && $originalName !== '') {
        $pathInfo = pathinfo($originalName);
        if (!empty($pathInfo['extension'])) {
            $extension = strtolower($pathInfo['extension']);
        }
    }

    $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/svg+xml' => 'svg',
    ];

    if (isset($mimeToExtension[$mimeType])) {
        $extension = $mimeToExtension[$mimeType];
    }

    if ($originalName === null || $originalName === '') {
        $originalName = 'featured-image.' . $extension;
    } elseif (pathinfo($originalName, PATHINFO_EXTENSION) === '') {
        $originalName .= '.' . $extension;
    }

    $tempFilePath = addUniquenessMarker($filePath, $mimeType);

    $postData = [
        'file' => curl_file_create($tempFilePath, $mimeType, $originalName),
        'caption' => 'Featured image',
    ];

    $url = wordPressRestUrl($site, 'wp/v2/media');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    @unlink($tempFilePath);

    if ($error) {
        return ['success' => false, 'message' => 'Image upload error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
        return ['success' => true, 'media_id' => (int)$decoded['id'], 'source_url' => (string) ($decoded['source_url'] ?? '')];
    }

    $detail = is_array($decoded) ? json_encode($decoded) : $response;
    return ['success' => false, 'message' => 'Image upload failed: ' . $detail];
}


/**
 * Upload a single image file to a site's WordPress media library.
 * Returns ['success'=>true,'media_id'=>int,'source_url'=>string] or ['success'=>false,'message'=>string].
 */
function uploadImageFileToWordPress(array $site, string $filePath, string $mimeType, string $filename): array
{
    if (!is_file($filePath)) {
        return ['success' => false, 'message' => 'Image file was not found.'];
    }

    $markedPath = addUniquenessMarker($filePath, $mimeType);
    $postData = ['file' => curl_file_create($markedPath, $mimeType, $filename)];

    $url = wordPressRestUrl($site, 'wp/v2/media');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    @unlink($markedPath);

    if ($error) {
        return ['success' => false, 'message' => 'Image upload error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
        return [
            'success' => true,
            'media_id' => (int) $decoded['id'],
            'source_url' => (string) ($decoded['source_url'] ?? ''),
        ];
    }

    $detail = is_array($decoded) ? json_encode($decoded) : $response;
    return ['success' => false, 'message' => 'Image upload failed: ' . $detail];
}


/**
 * WYSIWYG editors embed pasted/uploaded images as base64 data URIs. Upload each
 * one to this site's media library and replace the data URI with the real media
 * URL, so the published post references proper WordPress media (and no huge
 * base64 blob is ever sent to OpenAI for paraphrasing or stored in the post).
 * Returns ['success'=>true,'html'=>string] or ['success'=>false,'message'=>string].
 */
function uploadInlineImagesToWordPress(array $site, string $html): array
{
    if ($html === '' || stripos($html, 'data:image/') === false) {
        return ['success' => true, 'html' => $html];
    }

    if (!preg_match_all('/data:image\/[a-zA-Z0-9.+-]+;base64,[a-zA-Z0-9\/+=\s]+?(?=["\')\s>])/', $html, $matches)) {
        return ['success' => true, 'html' => $html];
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp',
        'image/svg+xml' => 'svg',
    ];

    $replacements = [];
    foreach (array_unique($matches[0]) as $dataUri) {
        if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/s', $dataUri, $parts)) {
            continue;
        }
        $mime = strtolower($parts[1]);
        $bin  = base64_decode(preg_replace('/\s+/', '', $parts[2]), true);
        if ($bin === false || $bin === '') {
            return ['success' => false, 'message' => 'An embedded image could not be decoded.'];
        }

        $ext = $mimeToExt[$mime] ?? 'jpg';
        $tmp = sys_get_temp_dir() . '/createposts-inline-' . uniqid('', true) . '.' . $ext;
        if (file_put_contents($tmp, $bin) === false) {
            return ['success' => false, 'message' => 'An embedded image could not be saved for upload.'];
        }

        $up = uploadImageFileToWordPress($site, $tmp, $mime, 'image-' . uniqid('', true) . '.' . $ext);
        @unlink($tmp);
        if (!$up['success']) {
            return ['success' => false, 'message' => $up['message']];
        }
        if ($up['source_url'] === '') {
            return ['success' => false, 'message' => 'An embedded image uploaded but no URL was returned.'];
        }
        $replacements[$dataUri] = $up['source_url'];
    }

    if (!empty($replacements)) {
        $html = strtr($html, $replacements);
    }
    return ['success' => true, 'html' => $html];
}


/** Pull out the full <img ...> tags from HTML (used to keep images when paraphrasing). */
function extractImageTags(string $html): array
{
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $m)) {
        return [];
    }
    return $m[0];
}


/** Convert editor HTML to readable plain text (block tags become line breaks, images dropped). */
function htmlToPlainText(string $html): string
{
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
    $html = preg_replace('/<\/(p|div|li|h[1-6]|tr|ul|ol|table|blockquote)\s*>/i', "\n", $html);
    $text = strip_tags($html); // also removes <img>
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}


/** Wrap plain text back into simple HTML paragraphs (blank lines split paragraphs). */
function plainTextToHtml(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $html = '';
    foreach (preg_split('/\n{2,}/', $text) as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        $html .= '<p>' . nl2br(htmlspecialchars($block, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    return $html;
}


function publishToWordPress(array $site, string $title, string $content, string $status = 'draft', ?string $featuredImagePath = null, ?string $featuredImageName = null, ?int $categoryId = null): array
{
    $payload = [
        'title' => $title,
        'content' => $content,
        'status' => $status,
    ];

    if ($categoryId) {
        $payload['categories'] = [$categoryId];
    }

    if ($featuredImagePath !== null && $featuredImagePath !== '') {
        $imageResult = uploadFeaturedImageToWordPress($site, $featuredImagePath, $featuredImageName ?? null);
        if (!$imageResult['success']) {
            return ['success' => false, 'message' => $imageResult['message']];
        }

        $payload['featured_media'] = $imageResult['media_id'];
    }

    $url = wordPressRestUrl($site, 'wp/v2/posts');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    // Large posts (plus plugins on the WP side) can take well over 30s; a too-low
    // timeout aborts the connection while WordPress still creates the post, which
    // we'd wrongly report as failed and the user would retry — causing duplicates.
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => 'Publish error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
        return ['success' => true, 'message' => 'Post published successfully.', 'post_id' => $decoded['id']];
    }

    $detail = is_array($decoded) ? json_encode($decoded) : $response;
    return ['success' => false, 'message' => 'WordPress API returned an error: ' . $detail];
}


/**
 * Create a minimal empty draft post on WordPress immediately.
 * Used to grab a post_id right away so Publer can link to it before
 * content processing (paraphrase, image upload) has finished.
 * Returns ['success'=>true,'post_id'=>int] or ['success'=>false,'message'=>string].
 */
function createWordPressDraft(array $site, string $title): array
{
    $payload = ['title' => $title, 'content' => '', 'status' => 'draft'];

    $url = wordPressRestUrl($site, 'wp/v2/posts');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => 'Draft creation error: ' . $error];
    }
    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
        return ['success' => true, 'post_id' => (int) $decoded['id']];
    }
    $detail = is_array($decoded) ? json_encode($decoded) : $response;
    return ['success' => false, 'message' => 'Draft creation failed (HTTP ' . $httpCode . '): ' . $detail];
}


/**
 * Fetch a single post's editable title/content, current status, and featured
 * image URL (if any) — used both for the Publish Queue's thumbnail and to
 * populate its "edit this post" form. context=edit asks WordPress for the
 * raw (unrendered) title/content, which is what an edit form should show and
 * resubmit — the rendered versions have HTML entities decoded that would get
 * double-encoded on save otherwise. Returns null on any failure.
 */
function getWordPressPostDetail(array $site, int $postId): ?array
{
    $url = wordPressRestUrl($site, 'wp/v2/posts/' . $postId . '?context=edit&_embed=wp:featuredmedia');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return null;
    }

    $imageUrl = null;
    $media = $data['_embedded']['wp:featuredmedia'][0] ?? null;
    if (is_array($media) && empty($media['code'])) { // a missing/invalid media id embeds an error object instead
        $imageUrl = (string) (
            $media['media_details']['sizes']['medium']['source_url']
            ?? $media['source_url']
            ?? ''
        );
        if ($imageUrl === '') {
            $imageUrl = null;
        }
    }

    return [
        'title'              => (string) ($data['title']['raw'] ?? $data['title']['rendered'] ?? ''),
        'content'            => (string) ($data['content']['raw'] ?? $data['content']['rendered'] ?? ''),
        'status'             => (string) ($data['status'] ?? 'publish'),
        'link'               => (string) ($data['link'] ?? ''),
        'featured_image_url' => $imageUrl,
    ];
}


/**
 * Same as getWordPressPostDetail(), cached in-session for a few minutes —
 * for the Publish Queue list, which only needs the thumbnail and would
 * otherwise re-fetch every job's post on every page view.
 */
function getWordPressPostDetailCached(array $site, int $postId): ?array
{
    $cacheKey = 'wp_post_detail_' . $site['id'] . '_' . $postId;
    $cached   = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['t'], $cached['data']) && (time() - (int) $cached['t']) < 300) {
        return $cached['data'];
    }
    $detail = getWordPressPostDetail($site, $postId);
    if ($detail !== null) {
        $_SESSION[$cacheKey] = ['t' => time(), 'data' => $detail];
    }
    return $detail;
}


/**
 * Update an existing WordPress post (title, content, status, optional featured
 * media id and category). Used in Phase 2 to fill in the draft created in Phase 1.
 * Returns ['success'=>true,'post_id'=>int] or ['success'=>false,'message'=>string].
 */
function updateWordPressPost(array $site, int $postId, string $title, string $content, string $status, ?int $featuredMediaId = null, ?int $categoryId = null): array
{
    $payload = ['title' => $title, 'content' => $content, 'status' => $status];
    if ($categoryId) {
        $payload['categories'] = [$categoryId];
    }
    if ($featuredMediaId !== null) {
        $payload['featured_media'] = $featuredMediaId;
    }

    $url = wordPressRestUrl($site, 'wp/v2/posts/' . $postId);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);          // WP REST accepts POST to /posts/{id}
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($site['username'] . ':' . $site['application_password']),
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => 'Post update error: ' . $error];
    }
    $decoded = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['id'])) {
        return ['success' => true, 'post_id' => (int) $decoded['id']];
    }
    $detail = is_array($decoded) ? json_encode($decoded) : $response;
    return ['success' => false, 'message' => 'WordPress update failed (HTTP ' . $httpCode . '): ' . $detail];
}


/**
 * Clean text copied from other websites: drop ad markers, source/credit lines,
 * image captions and standalone (image) URLs, and tidy blank lines.
 */
function cleanPostText(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $dropLine = [
        '/^\s*(advertisement|advertisements|sponsored|sponsored content|ad)\s*$/i',
        '/^\s*source\s*[:\-].*/i',
        // A separator ( | ( – — - ) followed by Source/Credit means the whole line is a caption.
        '/[|(\x{2013}\x{2014}\-]\s*(source|image source|photo source|photo credit|image credit|credit)\s*[:\-].*/iu',
        '/^\s*(image|photo|picture|video)\s*(source|credit)?\s*[:\-].*/i',
        '/^\s*(caption|credit|photo credit|image source|via)\s*[:\-].*/i',
        '/^\s*(getty images|shutterstock|istock|reuters|ap photo|afp|©.*)\s*$/i',
        '/^\s*https?:\/\/\S+\s*$/i',                         // standalone URL (often an image)
        '/^\s*\(.*\b(photo|image|source|credit|getty)\b.*\)\s*$/i', // (Photo: ...) caption lines
        '/^\s*(read more|read also|also read|related)\s*[:\-].*/i',
    ];

    $out = [];
    foreach ($lines as $line) {
        $skip = false;
        foreach ($dropLine as $rx) {
            if (preg_match($rx, $line)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        // Inline removals (word anywhere on the line).
        $line = preg_replace('/\b(advertisement|advertisements)\b\s*/i', '', $line);
        $line = str_replace('*', '', $line);              // strip asterisks (markdown/bullets)
        $out[] = $line;
    }

    $text = implode("\n", $out);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);  // collapse runs of spaces
    $text = preg_replace("/[ \t]+\n/", "\n", $text);  // trailing spaces
    $text = preg_replace("/\n{3,}/", "\n\n", $text);  // collapse blank lines
    return trim($text);
}
