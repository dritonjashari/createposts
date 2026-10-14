<?php


// ===========================================================================
// Publish to Websites — shared publish step + background job queue
// ===========================================================================

/**
 * Publish one prepared article to a single WordPress site: resolve inline
 * images, optionally paraphrase (plain text, images re-attached), resolve the
 * category, then create the post. Shared by the synchronous publish flow and
 * the background worker so both behave identically.
 * Returns ['success'=>true,'post_id'=>int,'title'=>string] or ['success'=>false,'message'=>string].
 */
/**
 * Publish (or update) one article to a single WordPress site.
 *
 * When $existingPostId is provided the function UPDATES that post instead of
 * creating a new one — this is the Phase-2 path where an empty draft was
 * already created in Phase 1 and we are now filling it with the final content.
 *
 * Returns ['success'=>true,'post_id'=>int,'title'=>string] or ['success'=>false,'message'=>string].
 */
function publishArticleToSite(array $site, int $ownerId, string $title, string $content, string $status, bool $paraphrase, string $tone, ?array $chosenCat, ?string $featuredImagePath, ?string $featuredImageName, int $index, int $siteCount, ?int $existingPostId = null, string $customPrompt = ''): array
{
    $postContent = $content;
    $postTitle   = $title;

    // Inline images -> this site's media library.
    $inline = uploadInlineImagesToWordPress($site, $postContent);
    if (!$inline['success']) {
        return ['success' => false, 'message' => $inline['message']];
    }
    $postContent = $inline['html'];

    if ($paraphrase) {
        // Paraphrase plain text only; re-attach any uploaded images afterwards.
        $images    = extractImageTags($postContent);
        $plainText = htmlToPlainText($postContent);

        $rebuilt = '';
        if ($plainText !== '') {
            $pr = paraphraseWithOpenAI($ownerId, $plainText, $tone, $site['site_name'], $index, $siteCount, $customPrompt);
            if (!$pr['success']) {
                return ['success' => false, 'message' => $pr['message']];
            }
            $rebuilt = plainTextToHtml($pr['text']);
        }
        foreach ($images as $imgTag) {
            $rebuilt .= '<p>' . $imgTag . '</p>';
        }
        $postContent = $rebuilt;

        if ($title !== '') {
            $tr = paraphraseWithOpenAI($ownerId, $title, $tone, $site['site_name'], $index, $siteCount, $customPrompt);
            if ($tr['success']) {
                $postTitle = trim($tr['text']);
            }
        }
    }

    // Resolve the category on this site (create if needed) when it applies.
    $catId = null;
    if ($chosenCat) {
        $applies = empty($chosenCat['site_id']) || (int) $chosenCat['site_id'] === (int) $site['id'];
        if ($applies) {
            $catId = resolveWordPressCategoryId($site, $chosenCat['name']);
        }
    }

    if ($existingPostId !== null) {
        // Phase-2 path: update the draft that was created in Phase 1.
        $featuredMediaId = null;
        if ($featuredImagePath !== null && $featuredImagePath !== '') {
            $imageResult = uploadFeaturedImageToWordPress($site, $featuredImagePath, $featuredImageName ?? null);
            if (!$imageResult['success']) {
                return ['success' => false, 'message' => $imageResult['message']];
            }
            $featuredMediaId = (int) $imageResult['media_id'];
            $featuredImageUrl = (string) ($imageResult['source_url'] ?? '');
        }
        $result = updateWordPressPost($site, $existingPostId, $postTitle, $postContent, $status, $featuredMediaId, $catId);
        if ($result['success']) {
            return ['success' => true, 'post_id' => $existingPostId, 'title' => $postTitle, 'image_url' => $featuredImageUrl ?? ''];
        }
        return ['success' => false, 'message' => $result['message']];
    }

    // Original path: create a brand-new post (synchronous / no-background flow).
    $result = publishToWordPress($site, $postTitle, $postContent, $status, $featuredImagePath, $featuredImageName, $catId);
    if ($result['success']) {
        return ['success' => true, 'post_id' => (int) $result['post_id'], 'title' => $postTitle];
    }
    return ['success' => false, 'message' => $result['message']];
}


function ensurePublishJobsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS publish_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            owner_id INT NOT NULL,
            title VARCHAR(512) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'publish',
            category_id INT NOT NULL DEFAULT 0,
            paraphrase TINYINT(1) NOT NULL DEFAULT 0,
            paraphrase_tone VARCHAR(32) NOT NULL DEFAULT 'neutral',
            paraphrase_custom_prompt TEXT NULL,
            featured_image_path VARCHAR(512) NULL,
            featured_image_name VARCHAR(255) NULL,
            site_ids TEXT NOT NULL,
            existing_draft_ids TEXT NULL,
            total INT NOT NULL DEFAULT 0,
            done INT NOT NULL DEFAULT 0,
            state VARCHAR(20) NOT NULL DEFAULT 'queued',
            results MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            CONSTRAINT fk_pj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    // Migrations: add columns for existing tables that pre-date these fields.
    try {
        getDb()->exec("ALTER TABLE publish_jobs ADD COLUMN paraphrase_custom_prompt TEXT NULL AFTER paraphrase_tone");
    } catch (Throwable $e) {
        // Column already exists — safe to ignore.
    }
    try {
        getDb()->exec("ALTER TABLE publish_jobs ADD COLUMN existing_draft_ids TEXT NULL AFTER site_ids");
    } catch (Throwable $e) {
        // Column already exists — safe to ignore.
    }
    $ensured = true;
}


/** Queue a publish job; returns the new job id. $d holds the parsed form data. */
function createPublishJob(int $ownerId, int $userId, array $d): int
{
    ensurePublishJobsTable();
    $stmt = getDb()->prepare(
        'INSERT INTO publish_jobs
            (user_id, owner_id, title, content, status, category_id, paraphrase, paraphrase_tone, paraphrase_custom_prompt, featured_image_path, featured_image_name, site_ids, total, state)
         VALUES
            (:uid, :oid, :title, :content, :status, :cat, :para, :tone, :custom, :fip, :fin, :sids, :total, "queued")'
    );
    $stmt->execute([
        'uid'    => $userId,
        'oid'    => $ownerId,
        'title'  => $d['title'],
        'content' => $d['content'],
        'status' => $d['status'],
        'cat'    => (int) $d['category_id'],
        'para'   => !empty($d['paraphrase']) ? 1 : 0,
        'tone'   => $d['paraphrase_tone'],
        'custom' => $d['paraphrase_custom_prompt'] ?? '',
        'fip'    => $d['featured_image_path'] ?? null,
        'fin'    => $d['featured_image_name'] ?? null,
        'sids'   => json_encode(array_values($d['site_ids'])),
        'total'  => count($d['site_ids']),
    ]);
    return (int) getDb()->lastInsertId();
}


/** Fetch a job for display (scoped to the account owner). */
function getPublishJob(int $id, int $ownerId): ?array
{
    ensurePublishJobsTable();
    $stmt = getDb()->prepare('SELECT * FROM publish_jobs WHERE id = :id AND owner_id = :oid');
    $stmt->execute(['id' => $id, 'oid' => $ownerId]);
    return $stmt->fetch() ?: null;
}


/**
 * A page of this owner's publish jobs, most recent first — the "Publish
 * Queue" page. When $postedBy is given (editors), only that user's own jobs
 * are returned; admins see every job under their account.
 */
function getPublishJobsForOwner(int $ownerId, int $limit, int $offset, ?int $postedBy = null): array
{
    ensurePublishJobsTable();
    $sql = 'SELECT pj.id, pj.title, pj.total, pj.done, pj.state, pj.results, pj.created_at, pj.finished_at,
                   pj.user_id, pj.featured_image_name, u.name AS posted_by_name
            FROM publish_jobs pj
            LEFT JOIN users u ON u.id = pj.user_id
            WHERE pj.owner_id = :oid';
    $params = ['oid' => $ownerId];
    if ($postedBy !== null) {
        $sql .= ' AND pj.user_id = :uid';
        $params['uid'] = $postedBy;
    }
    $sql .= ' ORDER BY pj.created_at DESC LIMIT :lim OFFSET :off';

    $stmt = getDb()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $jobs = [];
    foreach ($stmt->fetchAll() as $job) {
        $results = json_decode((string) ($job['results'] ?? ''), true);
        $job['results'] = is_array($results) ? $results : [];
        $failCount = 0;
        foreach ($job['results'] as $r) {
            if (empty($r['success']) && empty($r['pending']) && empty($r['retried'])) {
                $failCount++;
            }
        }
        $job['fail_count'] = $failCount;

        // Every successfully published site (each can be edited), and a
        // thumbnail if one was recorded at publish time. This list never
        // calls WordPress — asking each site for its post (as before) made a
        // page of 10 jobs take 5-7 s; older jobs' thumbnails are fetched
        // lazily and cached instead (publish_jobThumbnailAction).
        $job['featured_image_url'] = null;
        $job['editable_site_id']   = null;
        $job['editable_post_id']   = null;
        $job['edit_targets']       = [];
        foreach ($job['results'] as $siteId => $r) {
            if (empty($r['success']) || empty($r['post_id'])) {
                continue;
            }
            $job['edit_targets'][] = ['site_id' => (int) $siteId, 'post_id' => (int) $r['post_id'], 'name' => (string) ($r['name'] ?? '')];
            if ($job['editable_site_id'] === null) {
                $job['editable_site_id'] = (int) $siteId;
                $job['editable_post_id'] = (int) $r['post_id'];
            }
            if ($job['featured_image_url'] === null && !empty($r['image_url'])) {
                $job['featured_image_url'] = (string) $r['image_url'];
            }
        }
        $job['has_image'] = !empty($job['featured_image_name']);
        unset($job['featured_image_path'], $job['content']); // not needed by the list (and can be large)

        $jobs[] = $job;
    }
    return $jobs;
}


/** Total count for getPublishJobsForOwner()'s pagination. */
function getPublishJobsCountForOwner(int $ownerId, ?int $postedBy = null): int
{
    ensurePublishJobsTable();
    $sql = 'SELECT COUNT(*) FROM publish_jobs WHERE owner_id = :oid';
    $params = ['oid' => $ownerId];
    if ($postedBy !== null) {
        $sql .= ' AND user_id = :uid';
        $params['uid'] = $postedBy;
    }
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}


/** Recently finished jobs with at least one site that failed — dashboard "needs attention". */
function getPublishJobsNeedingAttention(int $ownerId, int $limit = 10, ?int $userId = null): array
{
    ensurePublishJobsTable();
    // $userId: only that member's own jobs (an editor's dashboard).
    $stmt = getDb()->prepare(
        "SELECT id, title, total, done, results, finished_at FROM publish_jobs
         WHERE owner_id = :oid AND state IN ('done', 'error') AND results IS NOT NULL
           AND finished_at >= (NOW() - INTERVAL 7 DAY)" . ($userId !== null ? ' AND user_id = :uid' : '') . "
         ORDER BY finished_at DESC
         LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute(['oid' => $ownerId] + ($userId !== null ? ['uid' => $userId] : []));

    $out = [];
    foreach ($stmt->fetchAll() as $job) {
        $results = json_decode((string) $job['results'], true);
        if (!is_array($results)) {
            continue;
        }
        $failCount = 0;
        foreach ($results as $r) {
            if (empty($r['success']) && empty($r['pending']) && empty($r['retried'])) {
                $failCount++;
            }
        }
        if ($failCount > 0) {
            $job['fail_count'] = $failCount;
            $out[] = $job;
        }
    }
    return $out;
}


/**
 * Long or multi-site jobs occasionally exceed a hard server-side execution
 * timeout (Apache/PHP-FPM/hosting-imposed, outside this app's control) and
 * the worker process is killed mid-run — no PHP code gets a chance to run
 * afterwards, so the job is stuck at 'processing' forever and never shows up
 * anywhere as failed. Call this at the start of every worker run to detect
 * and recover any job that's been "processing" for too long: whichever
 * sites never got a final result are marked failed (preserving any draft
 * already created, so a retry updates it instead of duplicating), and the
 * job is closed out as 'error' so it surfaces in the Publish Queue / Needs
 * Attention and can actually be retried instead of vanishing silently.
 */
function reapStalePublishJobs(int $staleMinutes = 15): void
{
    ensurePublishJobsTable();
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT * FROM publish_jobs
         WHERE state = 'processing' AND started_at IS NOT NULL AND started_at < (NOW() - INTERVAL :m MINUTE)"
    );
    $stmt->bindValue(':m', $staleMinutes, PDO::PARAM_INT);
    $stmt->execute();

    foreach ($stmt->fetchAll() as $job) {
        $siteIds = json_decode((string) $job['site_ids'], true);
        $siteIds = is_array($siteIds) ? $siteIds : [];
        $results = json_decode((string) ($job['results'] ?? ''), true);
        $results = is_array($results) ? $results : [];

        foreach ($siteIds as $siteId) {
            $key      = (string) $siteId;
            $existing = $results[$key] ?? null;
            if ($existing && (!empty($existing['success']) || !empty($existing['retried']))) {
                continue; // leave completed/already-handled sites alone
            }
            $entry = [
                'name'    => $existing['name'] ?? ('Site #' . $siteId),
                'success' => false,
                'message' => 'Timed out — the server stopped responding partway through. Please retry.',
            ];
            if (!empty($existing['draft_post_id'])) {
                $entry['draft_post_id'] = $existing['draft_post_id'];
            }
            $results[$key] = $entry;
        }

        $db->prepare("UPDATE publish_jobs SET state = 'error', finished_at = NOW(), results = :r WHERE id = :id")
           ->execute(['r' => json_encode($results), 'id' => $job['id']]);

        try {
            sendPublishFailureAlert((int) $job['owner_id'], $job, $results);
        } catch (Throwable $e) {
            // Best-effort alert only.
        }
    }
}


/** Atomically claim the next queued job (marks it processing). Null when none. */
function claimNextPublishJob(): ?array
{
    ensurePublishJobsTable();
    $db = getDb();
    $db->beginTransaction();
    try {
        $job = $db->query("SELECT * FROM publish_jobs WHERE state = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) {
            $db->commit();
            return null;
        }
        $db->prepare("UPDATE publish_jobs SET state = 'processing', started_at = NOW() WHERE id = :id")
           ->execute(['id' => $job['id']]);
        $db->commit();
        $job['state'] = 'processing';
        return $job;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return null;
    }
}


/**
 * Process a claimed job using a two-phase approach:
 *
 * Phase 1 — runs immediately for ALL sites before any slow work:
 *   Create an empty draft post on each WordPress site right away, log it in
 *   published_posts with status='draft', and store the post_id in results.
 *   This lets the Publer schedule page use the post link straight away without
 *   waiting for paraphrase or image uploads to finish.
 *
 * Phase 2 — runs per-site (can be slow):
 *   Upload inline images, paraphrase content/title if requested, upload the
 *   featured image, then UPDATE the existing draft with the final content and
 *   flip its status to whatever the user chose (draft/publish).  The
 *   published_posts record is updated to reflect the final title and status.
 */
function runPublishJob(array $job): void
{
    @set_time_limit(0);
    @ignore_user_abort(true);

    $ownerId    = (int) $job['owner_id'];
    $userId     = (int) $job['user_id'];
    $siteIds    = json_decode((string) $job['site_ids'], true);
    $siteIds    = is_array($siteIds) ? array_values(array_filter(array_map('intval', $siteIds))) : [];
    $siteCount  = count($siteIds);
    $paraphrase = !empty($job['paraphrase']);

    // Resolve the chosen category (by our id) once.
    $chosenCat = null;
    if ((int) $job['category_id'] > 0) {
        foreach (getCategories($ownerId) as $c) {
            if ((int) $c['id'] === (int) $job['category_id']) { $chosenCat = $c; break; }
        }
    }

    $results     = [];
    $draftPostIds = []; // siteId (int) => WordPress post_id (int)

    // A retry (see retryPublishJobFailures()) points certain sites at the draft
    // Phase 1 already created on the earlier failed attempt, so it's finished
    // rather than duplicated.
    $existingDraftIds = json_decode((string) ($job['existing_draft_ids'] ?? ''), true);
    $existingDraftIds = is_array($existingDraftIds) ? $existingDraftIds : [];

    // ── Phase 1: create empty drafts on every site right now ────────────────
    foreach ($siteIds as $siteId) {
        $site = getWordPressSiteById($siteId, $ownerId);
        if (!$site) {
            $results[(string) $siteId] = ['name' => 'Unknown site', 'success' => false, 'message' => 'Site not found.'];
            continue;
        }

        if (!empty($existingDraftIds[$siteId])) {
            $draftPostId = (int) $existingDraftIds[$siteId];
            $draftPostIds[$siteId] = $draftPostId;
            $results[(string) $siteId] = [
                'name'          => $site['site_name'],
                'success'       => false,
                'pending'       => true,
                'draft_post_id' => $draftPostId,
                'title'         => (string) $job['title'],
            ];
            continue;
        }

        $draft = createWordPressDraft($site, (string) $job['title']);
        if ($draft['success']) {
            $draftPostId = (int) $draft['post_id'];
            $draftPostIds[$siteId] = $draftPostId;
            // Log immediately so the Publer page can pick up the post link.
            logPublishedPost($ownerId, (int) $siteId, (string) $job['title'], $draftPostId, 'draft', $userId, $site['site_name'], $site['site_url']);
            $results[(string) $siteId] = [
                'name'          => $site['site_name'],
                'success'       => false,
                'pending'       => true,
                'draft_post_id' => $draftPostId,
                'title'         => (string) $job['title'],
            ];
        } else {
            $results[(string) $siteId] = [
                'name'    => $site['site_name'],
                'success' => false,
                'message' => $draft['message'],
            ];
        }
    }

    // Persist Phase 1 state so the status page shows drafts were created.
    getDb()->prepare('UPDATE publish_jobs SET results = :r WHERE id = :id')
           ->execute(['r' => json_encode($results), 'id' => $job['id']]);

    // ── Phase 2: process content and update each draft ───────────────────────
    $done = 0;
    foreach ($siteIds as $index => $siteId) {
        // If Phase 1 failed for this site there is nothing to update.
        if (!isset($draftPostIds[$siteId])) {
            $done++;
            getDb()->prepare('UPDATE publish_jobs SET done = :d, results = :r WHERE id = :id')
                   ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $job['id']]);
            continue;
        }

        $site        = getWordPressSiteById($siteId, $ownerId);
        $draftPostId = $draftPostIds[$siteId];

        $r = publishArticleToSite(
            $site, $ownerId, (string) $job['title'], (string) $job['content'], (string) $job['status'],
            $paraphrase, (string) $job['paraphrase_tone'], $chosenCat,
            $job['featured_image_path'] ?: null, $job['featured_image_name'] ?: null,
            $index, $siteCount,
            $draftPostId,  // ← tells the function to UPDATE rather than CREATE
            (string) ($job['paraphrase_custom_prompt'] ?? '')
        );

        if (!empty($r['success'])) {
            // Update the published_posts row we created in Phase 1.
            updatePublishedPostRecord($ownerId, (int) $siteId, $draftPostId, (string) $r['title'], (string) $job['status'], $userId, $site['site_name'], $site['site_url']);
            $results[(string) $siteId] = [
                'name'    => $site['site_name'],
                'success' => true,
                'post_id' => $draftPostId,
                'title'   => (string) $r['title'],
            ];
            if (!empty($r['image_url'])) {
                // Kept so the Publish Queue can show a thumbnail without asking WordPress.
                $results[(string) $siteId]['image_url'] = (string) $r['image_url'];
            }
        } else {
            // Keep the draft's post id even on failure — the empty draft Phase 1
            // created IS still sitting on the site, and retryPublishJobFailures()
            // needs this id to finish it later instead of creating a duplicate.
            $results[(string) $siteId] = [
                'name'          => $site['site_name'],
                'success'       => false,
                'draft_post_id' => $draftPostId,
                'message'       => $r['message'] ?? 'Failed.',
            ];
        }

        $done++;
        getDb()->prepare('UPDATE publish_jobs SET done = :d, results = :r WHERE id = :id')
               ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $job['id']]);
    }

    // Only clean up the persisted featured image once every site succeeded —
    // a failed site's retry (see retryPublishJobFailures()) still needs it.
    $anyFailed = false;
    foreach ($results as $r) {
        if (empty($r['success'])) { $anyFailed = true; break; }
    }
    if (!$anyFailed && !empty($job['featured_image_path']) && is_file($job['featured_image_path'])) {
        @unlink($job['featured_image_path']);
    }
    getDb()->prepare("UPDATE publish_jobs SET state = 'done', done = :d, results = :r, finished_at = NOW() WHERE id = :id")
           ->execute(['d' => $done, 'r' => json_encode($results), 'id' => $job['id']]);

    @sendPublishFailureAlert($ownerId, $job, $results);
}


/**
 * Re-attempt only the sites that failed in a finished publish job — reusing
 * the same title/content/settings and, crucially, the SAME draft post Phase 1
 * already created for each failed site (never re-created), so a retry can
 * never leave a duplicate post behind. Sites that already succeeded are left
 * untouched. Returns the new job id, or an error message if there's nothing
 * to retry.
 */
function retryPublishJobFailures(int $jobId, int $ownerId, int $userId): array
{
    $job = getPublishJob($jobId, $ownerId);
    if (!$job) {
        return ['success' => false, 'message' => 'Job not found.'];
    }
    if (!in_array($job['state'], ['done', 'error'], true)) {
        return ['success' => false, 'message' => 'This job is still running — wait for it to finish first.'];
    }

    $results = json_decode((string) ($job['results'] ?? '[]'), true);
    $results = is_array($results) ? $results : [];

    $failedSiteIds    = [];
    $existingDraftIds = []; // siteId => post_id, when Phase 1 already created a draft for it
    foreach ($results as $siteId => $r) {
        if (!empty($r['success']) || !empty($r['retried'])) {
            continue; // already published, or already handed off to a newer retry job
        }
        $failedSiteIds[] = (int) $siteId;
        if (!empty($r['draft_post_id'])) {
            $existingDraftIds[(int) $siteId] = (int) $r['draft_post_id'];
        }
    }

    if (empty($failedSiteIds)) {
        return ['success' => false, 'message' => 'No failed sites to retry.'];
    }
    // Never quietly publish without the featured image the original had.
    if (!empty($job['featured_image_path']) && !is_file($job['featured_image_path'])) {
        return ['success' => false, 'message' => 'The featured image of this post is no longer on the server, so it cannot be retried as-is — publish it again from Publish to Websites.'];
    }

    $newJobId = createPublishJob($ownerId, $userId, [
        'title'                    => (string) $job['title'],
        'content'                  => (string) $job['content'],
        'status'                   => (string) $job['status'],
        'category_id'              => (int) $job['category_id'],
        'paraphrase'               => (bool) $job['paraphrase'],
        'paraphrase_tone'          => (string) $job['paraphrase_tone'],
        'paraphrase_custom_prompt' => (string) ($job['paraphrase_custom_prompt'] ?? ''),
        // The original temp upload is long gone by now, but the *persisted*
        // copy runPublishJob() kept (because this job still had a failure)
        // is reused here — still cleaned up once the retry fully succeeds.
        'featured_image_path'      => $job['featured_image_path'] ?: null,
        'featured_image_name'      => $job['featured_image_name'] ?? null,
        'site_ids'                 => $failedSiteIds,
    ]);

    if (!empty($existingDraftIds)) {
        getDb()->prepare('UPDATE publish_jobs SET existing_draft_ids = :d WHERE id = :id')
               ->execute(['d' => json_encode($existingDraftIds), 'id' => $newJobId]);
    }

    // Mark the failed sites on the ORIGINAL job as handed off to this retry —
    // otherwise getPublishJobsNeedingAttention() keeps surfacing the same old
    // failure forever (its own `results` never changes), making "Retry failed
    // sites" look like a no-op even though a new job is quietly running.
    foreach ($failedSiteIds as $siteId) {
        $results[(string) $siteId]['retried']          = true;
        $results[(string) $siteId]['retried_as_job_id'] = $newJobId;
    }
    getDb()->prepare('UPDATE publish_jobs SET results = :r WHERE id = :id')
           ->execute(['r' => json_encode($results), 'id' => $jobId]);

    return ['success' => true, 'job_id' => $newJobId];
}


/**
 * Best-effort immediate kick of the background worker over HTTP so a queued job
 * starts without waiting for the next cron tick. Fire-and-forget: a failure is
 * harmless because cron will pick the job up anyway.
 */
function triggerPublishWorkerAsync(): void
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // The JSON API's front controller lives one level down, in an /api subdir —
    // publish_worker.php sits next to it in the project root either way.
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    $url    = $scheme . '://' . $host . $dir . '/publish_worker.php?token=' . rawurlencode(PUBLISH_WORKER_TOKEN);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300); // return almost immediately
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    curl_close($ch);
}
