<?php
// Publish to Websites. Mirrors publish.php + publish_status.php.
//
// Note: the legacy page's one-time publish_tokens guard exists to stop a
// double-publish when a browser reloads/resubmits a stuck form — a problem
// specific to server-rendered forms. An SPA issues one fetch() per click, so
// that guard is intentionally not ported here.

function publish_contextAction(): void
{
    requireLoginApi();
    $userId      = currentUserId();
    $ownerId     = getSiteOwnerId($userId);
    $isAdminUser = isAdmin();

    $sites = getWordPressSitesForUser($ownerId);

    // Editors may only publish to sites mapped to a Facebook page in one of
    // their allowed workspaces — a site with no such mapping is off-limits,
    // never a silent fallback to "show every site" just because nothing is
    // mapped yet. Admins (account owners) are never restricted this way.
    $filteredByWorkspace = false;
    if (!$isAdminUser) {
        $mappedSiteIds = getMappedSiteIdsForAllowedWorkspaces($ownerId, $userId);
        $sites = array_values(array_filter($sites, static fn ($s) => in_array((int) $s['id'], $mappedSiteIds, true)));
        $filteredByWorkspace = true;
    }

    jsonResponse([
        'sites'                       => $sites,
        'sites_filtered_by_workspace' => $filteredByWorkspace,
        'categories'                  => getCategories($ownerId),
        'default_category'           => getDefaultCategory($ownerId),
        'is_admin'                   => $isAdminUser,
        'auto_clean_default'         => getSetting($ownerId, 'auto_clean_text', '0') === '1',
        'default_paraphrase_web'     => getSetting($ownerId, 'default_paraphrase_web', '0') === '1',
    ]);
}

function publish_createAction(): void
{
    requireLoginApi();
    $userId      = currentUserId();
    $ownerId     = getSiteOwnerId($userId);
    $isAdminUser = isAdmin();

    // Don't let a dropped connection or PHP's execution limit abort a slow
    // synchronous publish partway through (same reasoning as publish.php).
    @ignore_user_abort(true);
    @set_time_limit(0);

    $autoCleanDefault = getSetting($ownerId, 'auto_clean_text', '0') === '1';

    $siteIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['site_ids'] ?? [])), static fn ($id) => $id > 0)));

    // Server-side enforcement (not just hiding options in the UI): an editor
    // can only submit sites mapped to a Facebook page in one of their allowed
    // workspaces, regardless of what site_ids a direct API call might send.
    $blockedByWorkspace = false;
    if (!$isAdminUser) {
        $allowedSiteIds = getMappedSiteIdsForAllowedWorkspaces($ownerId, $userId);
        $beforeCount = count($siteIds);
        $siteIds = array_values(array_filter($siteIds, static fn ($id) => in_array($id, $allowedSiteIds, true)));
        $blockedByWorkspace = $beforeCount > 0 && empty($siteIds);
    }

    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $doClean = $isAdminUser ? !empty($_POST['clean_text']) : $autoCleanDefault;
    if ($doClean) {
        $content = cleanPostText($content);
    }
    $status         = trim($_POST['status'] ?? 'draft');
    $paraphrase           = !empty($_POST['paraphrase']);
    $paraphraseTone       = trim($_POST['paraphrase_tone'] ?? 'neutral');
    $paraphraseCustomPrompt = trim($_POST['paraphrase_custom_prompt'] ?? '');

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $chosenCat  = null;
    if ($categoryId > 0) {
        foreach (getCategories($ownerId) as $c) {
            if ((int) $c['id'] === $categoryId) { $chosenCat = $c; break; }
        }
    }

    if ($title === '' || $content === '') {
        jsonError(422, 'Please enter a title and content.');
    }
    if ($blockedByWorkspace) {
        jsonError(422, 'None of the selected sites are mapped to a Facebook page in a workspace you have access to.');
    }
    if (empty($siteIds)) {
        jsonError(422, 'Please choose at least one site.');
    }

    $featuredImagePath = null;
    $featuredImageName = null;
    $featuredImageExt  = '';
    if (!empty($_FILES['featured_image']['tmp_name']) && is_uploaded_file($_FILES['featured_image']['tmp_name'])) {
        // Only real images — the file is kept under the web root, so its
        // name/extension must never come from the user (".php" would run).
        $imgType = detectMediaFileType($_FILES['featured_image']['tmp_name']);
        if ($imgType === null || !str_starts_with($imgType['mime'], 'image/')) {
            jsonError(422, 'The featured image must be a JPG, PNG, GIF or WebP picture.');
        }
        $featuredImagePath = $_FILES['featured_image']['tmp_name'];
        $featuredImageExt  = $imgType['ext'];
        $featuredImageName = safeMediaName((string) $_FILES['featured_image']['name'], $featuredImageExt);
    }

    if (BACKGROUND_PUBLISHING) {
        // Persist the featured image — the PHP upload tmp file is gone by the
        // time the worker runs.
        $persistedImagePath = null;
        if ($featuredImagePath !== null) {
            $jobsDir = __DIR__ . '/../../uploads/jobs';
            if (!is_dir($jobsDir)) {
                @mkdir($jobsDir, 0775, true);
            }
            $dest = $jobsDir . '/job-' . bin2hex(random_bytes(8)) . '.' . $featuredImageExt;
            if (@move_uploaded_file($featuredImagePath, $dest)) {
                $persistedImagePath = $dest;
            }
        }

        $jobId = createPublishJob($ownerId, $userId, [
            'title'                    => $title,
            'content'                  => $content,
            'status'                   => $status,
            'category_id'              => $categoryId,
            'paraphrase'               => $paraphrase,
            'paraphrase_tone'          => $paraphraseTone,
            'paraphrase_custom_prompt' => $paraphraseCustomPrompt,
            'featured_image_path'      => $persistedImagePath,
            'featured_image_name'      => $featuredImageName,
            'site_ids'                 => $siteIds,
        ]);

        triggerPublishWorkerAsync(); // start now if possible; cron is the backstop

        jsonResponse(['job_id' => $jobId], 202);
    }

    // --- Synchronous fallback (set BACKGROUND_PUBLISHING = false in config.php) ---
    $successCount = 0;
    $successSites = [];
    $failures     = [];
    $siteCount    = count($siteIds);
    foreach ($siteIds as $index => $siteId) {
        $site = getWordPressSiteById($siteId, $ownerId);
        if (!$site) {
            $failures[] = 'A selected site could not be found.';
            continue;
        }
        $r = publishArticleToSite($site, $ownerId, $title, $content, $status, $paraphrase, $paraphraseTone, $chosenCat, $featuredImagePath, $featuredImageName, $index, $siteCount, null, $paraphraseCustomPrompt);
        if (!empty($r['success'])) {
            logPublishedPost($ownerId, $siteId, (string) $r['title'], (int) $r['post_id'], $status, $userId, $site['site_name'], $site['site_url']);
            $successCount++;
            $successSites[] = $site['site_name'];
        } else {
            $failures[] = $site['site_name'] . ': ' . ($r['message'] ?? 'Failed.');
        }
    }

    jsonResponse([
        'success_count' => $successCount,
        'success_sites' => $successSites,
        'failures'      => $failures,
    ]);
}

/**
 * Import an article from another website (title, text across all of its
 * pages, featured image) to pre-fill the Publish form. Nothing is published
 * or stored here — the user reviews and edits the fields first.
 */
function publish_importAction(): void
{
    requireLoginApi();
    $url = trim((string) (jsonBody()['url'] ?? ''));
    if ($url === '') {
        jsonError(422, 'Please paste the address of the post to import.');
    }
    @set_time_limit(150);
    $result = scrapeArticle($url);
    if (!$result['success']) {
        jsonError(422, $result['message']);
    }
    unset($result['success']);
    jsonResponse($result);
}

function publish_inpaintAction(): void
{
    requireLoginApi();
    // The OpenAI key lives on the team owner's account (editors and co-admins
    // don't have their own), same as paraphrasing.
    $userId = getSiteOwnerId(currentUserId());

    $stmt = getDb()->prepare('SELECT openai_api_key FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $apiKey = trim((string) $stmt->fetchColumn());

    if ($apiKey === '') {
        jsonError(403, 'OpenAI API key is not configured.');
    }

    $prompt = trim($_POST['prompt'] ?? '');
    if ($prompt === '') {
        jsonError(422, 'Please provide an edit prompt for the image.');
    }
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        jsonError(422, 'Please upload an image to edit.');
    }

    $imagePath = $_FILES['image']['tmp_name'];
    $fileName = basename((string) ($_FILES['image']['name'] ?? 'image.png'));
    $mimeType = (string) (function_exists('mime_content_type') ? mime_content_type($imagePath) : 'image/png');
    if ($mimeType === '') {
        $mimeType = 'image/png';
    }

    $payload = [
        'model' => 'gpt-image-1',
        'prompt' => $prompt,
        'image[]' => new CURLFile($imagePath, $mimeType, $fileName),
    ];

    $ch = curl_init('https://api.openai.com/v1/images/edits');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        jsonError(502, 'OpenAI request error: ' . $error);
    }

    $decoded = json_decode((string) $raw, true);
    if ($code >= 200 && $code < 300 && !empty($decoded['data'][0]['b64_json'])) {
        $base64 = (string) $decoded['data'][0]['b64_json'];
        $outputName = preg_replace('/\.[^.]+$/', '', $fileName) ?: 'image';
        $outputName .= '-edited.png';
        jsonResponse([
            'file_name' => $outputName,
            'mime_type' => 'image/png',
            'base64' => $base64,
        ]);
    }

    $detail = is_array($decoded) ? json_encode($decoded) : (string) $raw;
    jsonError(502, 'OpenAI image edit failed: ' . $detail);
}

function publish_jobsListAction(): void
{
    requireLoginApi();
    kickStalledWorkers();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $perPage = 10;
    $page    = max(1, (int) ($_GET['page'] ?? 1));

    // Admins see every publish job under their account; editors only their own.
    $postedByFilter = isAdmin() ? null : $userId;

    $total      = getPublishJobsCountForOwner($ownerId, $postedByFilter);
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    jsonResponse([
        'jobs'        => getPublishJobsForOwner($ownerId, $perPage, ($page - 1) * $perPage, $postedByFilter),
        'page'        => $page,
        'total_pages' => $totalPages,
        'total'       => $total,
        'is_admin'    => isAdmin(),
    ]);
}

/**
 * Resolve + authorize a (job, site) pair for the post-edit actions below.
 * Exits via jsonError() on any failure. Returns [$site, $postId].
 */
function _publish_resolveEditablePost(string $jobId, string $siteId): array
{
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);

    $job = getPublishJob((int) $jobId, $ownerId);
    if (!$job) {
        jsonError(404, 'Job not found.');
    }
    if (!isAdmin() && (int) $job['user_id'] !== $userId) {
        jsonError(403, 'You can only edit your own posts.');
    }

    $results = json_decode((string) ($job['results'] ?? ''), true);
    $results = is_array($results) ? $results : [];
    $siteResult = $results[$siteId] ?? null;
    if (!is_array($siteResult) || empty($siteResult['success']) || empty($siteResult['post_id'])) {
        jsonError(404, 'This post is not available — the site may have failed or is still processing.');
    }

    $site = getWordPressSiteById((int) $siteId, $ownerId);
    if (!$site) {
        jsonError(404, 'Site not found.');
    }

    return [$site, (int) $siteResult['post_id']];
}

function publish_jobPostDetailAction(string $jobId, string $siteId): void
{
    requireLoginApi();
    [$site, $postId] = _publish_resolveEditablePost($jobId, $siteId);

    $detail = getWordPressPostDetail($site, $postId);
    if ($detail === null) {
        jsonError(502, 'Could not load this post from WordPress.');
    }
    jsonResponse($detail);
}

function publish_jobPostUpdateAction(string $jobId, string $siteId): void
{
    requireLoginApi();
    [$site, $postId] = _publish_resolveEditablePost($jobId, $siteId);

    $body    = jsonBody();
    $title   = trim((string) ($body['title'] ?? ''));
    $content = trim((string) ($body['content'] ?? ''));
    if ($title === '' || $content === '') {
        jsonError(422, 'Please enter a title and content.');
    }

    // Preserve whatever status the post currently has on WordPress rather
    // than assuming — this endpoint only edits title/content.
    $current = getWordPressPostDetail($site, $postId);
    $status  = $current['status'] ?? 'publish';

    $result = updateWordPressPost($site, $postId, $title, $content, $status);
    if (empty($result['success'])) {
        jsonError(502, $result['message'] ?? 'Could not update the post.');
    }

    jsonResponse(['message' => 'Post updated.']);
}

function publish_jobStatusAction(string $jobId): void
{
    requireLoginApi();
    kickStalledWorkers();
    $ownerId = getSiteOwnerId(currentUserId());
    $job     = getPublishJob((int) $jobId, $ownerId);
    if (!$job) {
        jsonError(404, 'Job not found.');
    }
    jsonResponse([
        'state'   => $job['state'],
        'total'   => (int) $job['total'],
        'done'    => (int) $job['done'],
        'results' => json_decode((string) ($job['results'] ?? 'null'), true),
    ]);
}

/**
 * Thumbnail of a published job's featured image, for jobs published before
 * the image address was recorded with the job. Asks WordPress once per post,
 * then caches the answer (also "no image") for a week, shared by the team.
 */
function publish_jobThumbnailAction(string $jobId): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    $job     = getPublishJob((int) $jobId, $ownerId);
    if (!$job || (!isAdmin() && (int) $job['user_id'] !== $userId)) {
        jsonError(404, 'Job not found.');
    }
    $results = json_decode((string) ($job['results'] ?? ''), true);
    foreach (is_array($results) ? $results : [] as $siteId => $r) {
        if (empty($r['success']) || empty($r['post_id'])) {
            continue;
        }
        if (!empty($r['image_url'])) {
            jsonResponse(['url' => (string) $r['image_url']]);
        }
        $key = 'wpthumb|' . (int) $siteId . '|' . (int) $r['post_id'];
        $url = publerCacheGet($key, 7 * 86400);
        if ($url === null) {
            $site   = getWordPressSiteById((int) $siteId, $ownerId);
            $detail = $site ? getWordPressPostDetail($site, (int) $r['post_id']) : null;
            if ($detail === null) {
                jsonResponse(['url' => null]); // WordPress unreachable — try again another time
            }
            $url = (string) ($detail['featured_image_url'] ?? '');
            publerCacheSet($key, $url);
        }
        jsonResponse(['url' => $url !== '' ? $url : null]);
    }
    jsonResponse(['url' => null]);
}

/** Only an admin, or the person who submitted the job, may retry it. */
function _publish_assertCanRetry(?array $job, int $userId): void
{
    if (!$job) {
        jsonError(404, 'Job not found.');
    }
    if (!isAdmin() && (int) $job['user_id'] !== $userId) {
        jsonError(403, 'You can only retry your own posts.');
    }
}

function publish_retryJobAction(string $jobId): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ownerId = getSiteOwnerId($userId);
    _publish_assertCanRetry(getPublishJob((int) $jobId, $ownerId), $userId);

    $result = retryPublishJobFailures((int) $jobId, $ownerId, $userId);
    if (empty($result['success'])) {
        jsonError(422, $result['message'] ?? 'Could not retry this job.');
    }

    triggerPublishWorkerAsync();
    jsonResponse(['job_id' => $result['job_id']], 202);
}
