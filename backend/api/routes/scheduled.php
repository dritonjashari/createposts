<?php
// Scheduled Posts Manager — list, reschedule, and delete Publer-queued posts.

function _scheduled_checkAccess(): void
{
    requireLoginApi();
    $ownerId = getSiteOwnerId(currentUserId());
    if (!isAdmin() && getSetting($ownerId, 'editors_can_schedule', '1') !== '1') {
        jsonError(403, 'Your admin has restricted access to scheduled posts.');
    }
}

/** Build a query string the way Publer expects array params: repeated key[]=value pairs. */
function _scheduled_buildQuery(array $params): string
{
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $parts[] = rawurlencode($key . '[]') . '=' . rawurlencode((string) $v);
            }
        } else {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
    }
    return implode('&', $parts);
}

/** One Publer post record in the shape the Post Queue page shows. */
function _scheduled_formatPost(array $p, array $fbPages): array
{
    // Publer's list response has ONE post record per Facebook page — the
    // account is a single top-level "account_id" (no "networks"/"accounts"
    // wrapper like the bulk *schedule* payload uses; that shape is specific
    // to writes, not this read). Publer doesn't return the page's name here,
    // so it's resolved from our own workspace page list.
    $accId = (string) ($p['account_id'] ?? '');
    $accAt = (string) ($p['scheduled_at'] ?? '');
    $accName = '';
    foreach ($fbPages as $fp) {
        if ($fp['id'] === $accId) { $accName = $fp['name']; break; }
    }
    $media = (array) ($p['media'] ?? []);
    $comments = array_values(array_filter(array_map(
        static fn ($c) => trim((string) ($c['text'] ?? '')),
        (array) ($p['comments'] ?? [])
    ), static fn ($c) => $c !== ''));

    return [
        'id'              => (string) ($p['id'] ?? ''),
        'text'            => (string) ($p['text'] ?? ''),
        'comments'        => $comments,
        'accounts'        => [['id' => $accId, 'name' => $accName, 'scheduled_at' => $accAt]],
        'scheduled_at'    => $accAt,
        'has_media'       => !empty($media),
        'media_thumbnail' => publerMediaThumbnail($media),
        'media_type'      => (string) ($media[0]['type'] ?? ''),
        'type'            => (string) ($p['type'] ?? 'status'),
        'state'           => (string) ($p['state'] ?? ''),
        'post_link'       => (string) ($p['post_link'] ?? ''),
    ];
}

function scheduled_listAction(): void
{
    _scheduled_checkAccess();
    $userId      = currentUserId();
    $workspaceId = fetchPublerWorkspaceId($userId);
    $apiKey      = getPublerApiKey($userId);
    $page        = max(1, (int) ($_GET['page'] ?? 1));
    $perPage     = 20;
    $state       = ($_GET['state'] ?? '') === 'published' ? 'published' : 'scheduled';
    $filterPage  = trim($_GET['account_id'] ?? '');
    $search      = trim($_GET['q'] ?? '');
    $dateFrom    = trim($_GET['date_from'] ?? '');
    $dateTo      = trim($_GET['date_to'] ?? '');

    if ($workspaceId === '') {
        jsonResponse(['posts' => [], 'total' => 0, 'pages' => [], 'page' => 1, 'total_pages' => 1, 'has_more' => false, 'state' => $state]);
        return;
    }

    // Published history can run into thousands of posts (newest first), so
    // it's paged straight through Publer — one request per page — instead
    // of collected in full like the (much shorter) scheduled queue below.
    // With a date range Publer ignores `page` (see the quirk noted below) and
    // returns the whole matching range at once, so that case falls through
    // to the collect-and-slice path instead.
    if ($state === 'published' && $dateFrom === '' && $dateTo === '') {
        $query = ['state' => 'published', 'page' => $page - 1];
        if ($filterPage !== '') {
            $query['account_ids'] = [$filterPage];
        }
        if ($search !== '') {
            $query['query'] = $search;
        }
        // Short shared cache: the whole team shares Publer's 100-requests-per-
        // 2-minutes limit; any change in the workspace invalidates it.
        $cacheKey = 'published|' . $workspaceId . '|' . publerCacheGen($workspaceId) . '|' . md5(json_encode($query));
        $data     = publerCacheGet($cacheKey, 45);
        if ($data === null) {
            $data = publerHttpGet('posts?' . _scheduled_buildQuery($query), $workspaceId, $apiKey);
            if ($data === null) {
                $code = (int) ($GLOBALS['publer_last_status'] ?? 0);
                jsonError($code === 429 ? 429 : 502, publerErrorMessage($code));
            }
            publerCacheSet($cacheKey, $data);
        }
        $batch   = is_array($data) && is_array($data['posts'] ?? null) ? $data['posts'] : [];
        $fbPages = fetchPublerFacebookPages($userId, $workspaceId);
        $posts   = [];
        foreach ($batch as $p) {
            if (is_array($p)) {
                $posts[] = _scheduled_formatPost($p, $fbPages);
            }
        }
        jsonResponse([
            'posts'       => $posts,
            'total'       => (int) ($data['total'] ?? count($posts)),
            'page'        => $page,
            'total_pages' => null,
            'has_more'    => count($posts) > 0,
            'pages'       => $fbPages,
            'state'       => $state,
        ]);
        return;
    }

    // Push our filters down to Publer itself (account_ids[]/query/from+to are
    // all real, documented filter params) instead of fetching everything and
    // filtering client-side — a workspace can easily have several hundred
    // scheduled posts, and "limit=200" used here previously isn't a real
    // Publer parameter at all (it was silently ignored), so this was quietly
    // truncated to whatever Publer's own default page size happens to be —
    // meaning most scheduled posts, for any page beyond the first ~15, never
    // showed up here at all even though they were genuinely scheduled.
    $query = ['state' => $state];
    if ($filterPage !== '') {
        $query['account_ids'] = [$filterPage];
    }
    if ($search !== '') {
        $query['query'] = $search;
    }
    if ($dateFrom !== '' || $dateTo !== '') {
        // Publer requires from/to together and expects plain YYYY-MM-DD dates.
        $query['from'] = $dateFrom !== '' ? $dateFrom : '2000-01-01';
        $query['to']   = $dateTo !== '' ? $dateTo : '2100-01-01';
    }

    // Publer paginates server-side (0-based `page`); loop until it returns an
    // empty page. A safety cap avoids an unbounded loop in the pathological
    // case, while comfortably covering realistic scheduled-post volumes.
    //
    // Undocumented Publer quirk, confirmed directly against their API: when
    // `from`/`to` are both present, `page` is silently ignored and every
    // request returns the SAME full matching set — without a de-dup guard
    // that loops forever (until the page cap) re-fetching identical,
    // multi-hundred-post responses, which is slow enough to look like the
    // Post Queue "shows nothing" (the request times out before it finishes).
    // Tracking seen ids and stopping once a "page" adds nothing new handles
    // that case in two requests, and costs nothing in the normal case.
    $cacheKey = 'queue|' . $workspaceId . '|' . publerCacheGen($workspaceId) . '|' . md5(json_encode($query));
    $cachedList = publerCacheGet($cacheKey, 45);
    $list = is_array($cachedList) ? $cachedList : [];
    $seenIds = [];
    $publerPage = 0;
    $maxPublerPages = is_array($cachedList) ? 0 : 60;
    $warning = null;
    while ($publerPage < $maxPublerPages) {
        $qs = $query;
        $qs['page'] = $publerPage;
        $data  = publerHttpGet('posts?' . _scheduled_buildQuery($qs), $workspaceId, $apiKey);
        if ($data === null) {
            // A Publer error (e.g. its rate limit) must never look like an
            // empty queue: fail outright, or show what was read so far.
            $code = (int) ($GLOBALS['publer_last_status'] ?? 0);
            if (empty($list)) {
                jsonError($code === 429 ? 429 : 502, publerErrorMessage($code));
            }
            $warning = 'Only part of the queue could be loaded. ' . publerErrorMessage($code);
            break;
        }
        $batch = $data['posts'] ?? [];
        if (!is_array($batch) || empty($batch)) {
            break;
        }
        $newCount = 0;
        foreach ($batch as $p) {
            $pid = (string) ($p['id'] ?? '');
            if ($pid !== '' && !isset($seenIds[$pid])) {
                $seenIds[$pid] = true;
                $list[] = $p;
                $newCount++;
            }
        }
        if ($newCount === 0) {
            break;
        }
        $publerPage++;
    }
    if (!is_array($cachedList) && $warning === null) {
        publerCacheSet($cacheKey, $list);
    }

    $fbPages = fetchPublerFacebookPages($userId, $workspaceId);

    $posts = [];
    foreach ((array) $list as $p) {
        if (!is_array($p)) {
            continue;
        }
        if ($filterPage !== '' && (string) ($p['account_id'] ?? '') !== $filterPage) {
            continue; // this post targets a different page
        }
        $posts[] = _scheduled_formatPost($p, $fbPages);
    }

    // Search — matches the post text or any attached comment.
    if ($search !== '') {
        $posts = array_values(array_filter($posts, static function (array $p) use ($search): bool {
            if (mb_stripos($p['text'], $search) !== false) {
                return true;
            }
            foreach ($p['comments'] as $c) {
                if (mb_stripos($c, $search) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    // Date range — inclusive, interpreted in the app's timezone.
    if ($dateFrom !== '' || $dateTo !== '') {
        $fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
        $toTs   = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;
        $posts  = array_values(array_filter($posts, static function (array $p) use ($fromTs, $toTs): bool {
            $ts = strtotime((string) $p['scheduled_at']);
            if ($ts === false) {
                return false;
            }
            if ($fromTs !== null && $ts < $fromTs) {
                return false;
            }
            if ($toTs !== null && $ts > $toTs) {
                return false;
            }
            return true;
        }));
    }

    // Upcoming posts soonest first; published history newest first.
    usort($posts, static fn ($a, $b) => strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']));
    if ($state === 'published') {
        $posts = array_reverse($posts);
    }

    $total      = count($posts);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $slice      = array_slice($posts, ($page - 1) * $perPage, $perPage);

    jsonResponse([
        'posts'       => $slice,
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'has_more'    => $page < $totalPages,
        'pages'       => $fbPages,
        'state'       => $state,
        'warning'     => $warning,
    ]);
}


/**
 * Repost an existing Publer post (scheduled or already published — e.g. one
 * that performed well) to other Facebook pages of the active workspace.
 * The post's text, comments and media are read back from Publer, then
 * queued as a normal schedule job, so it gets the same per-page handling as
 * a fresh post: optional white-pixel marking, AI rewrite, page gaps,
 * timeslots, auto-delete, progress and retry.
 */
function scheduled_repostAction(string $postId): void
{
    _scheduled_checkAccess();
    @set_time_limit(300);
    $userId      = currentUserId();
    $isAdminUser = isAdmin();
    $apiKey      = getPublerApiKey($userId);
    $ctx         = publer_loadContext($userId);
    $ownerId     = $ctx['ownerId'];
    $workspaceId = $ctx['workspaceId'];
    $body        = jsonBody();

    if ($workspaceId === '') {
        jsonError(422, 'Could not resolve your Publer workspace. Check that the API key is valid.');
    }
    $accountIds = array_values(array_unique(array_filter(array_map(
        static fn ($id) => trim((string) $id),
        (array) ($body['account_ids'] ?? [])
    ), static fn ($id) => $id !== '')));
    if (empty($accountIds)) {
        jsonError(422, 'Please choose at least one Facebook page to repost to.');
    }
    publer_requireWorkspacePages($accountIds, $ctx['facebookPages']);

    // The source post, read fresh from Publer (never trusted from the browser).
    $res = publerRequest('GET', 'posts/' . rawurlencode($postId), $apiKey, $workspaceId);
    if ($res['error'] !== '') {
        jsonError(502, 'Could not reach Publer: ' . $res['error']);
    }
    if ($res['code'] === 404 || ($res['code'] < 400 && !is_array($res['data']))) {
        jsonError(404, 'That post no longer exists in Publer.');
    }
    if ($res['code'] >= 400) {
        jsonError($res['code'] === 429 ? 429 : 502, publerErrorMessage($res['code']));
    }
    $src  = $res['data'];
    $text = trim((string) ($src['text'] ?? ''));
    $link = trim((string) ($src['url'] ?? ''));
    if ($link !== '' && !str_contains($text, $link)) {
        $text = trim($text . "\n\n" . $link); // link posts: keep the link
    }

    // Comments, keeping each one's original delay after the post went live.
    $comments = [];
    if (!array_key_exists('include_comments', $body) || !empty($body['include_comments'])) {
        $postTs = strtotime((string) ($src['scheduled_at'] ?? '')) ?: null;
        foreach ((array) ($src['comments'] ?? []) as $c) {
            $ct = trim((string) ($c['text'] ?? ''));
            if ($ct === '') {
                continue;
            }
            $cTs = strtotime((string) ($c['time'] ?? '')) ?: null;
            $comments[] = ['text' => $ct, 'delay' => ($postTs && $cTs && $cTs > $postTs) ? (int) round(($cTs - $postTs) / 60) : 0];
            if (count($comments) === 2) {
                break; // the schedule pipeline carries up to two comments
            }
        }
    }

    $mediaItems = array_values(array_filter((array) ($src['media'] ?? []), 'is_array'));
    if ($text === '' && empty($mediaItems)) {
        jsonError(422, 'That post has no text or media to repost.');
    }

    $paraphrase         = !empty($body['paraphrase']);
    $paraphraseComments = !empty($body['paraphrase_comments']);
    if ($paraphrase || ($paraphraseComments && !empty($comments))) {
        $stmt = getDb()->prepare('SELECT openai_api_key FROM users WHERE id = :id');
        $stmt->execute(['id' => $ownerId]);
        if (empty($stmt->fetchColumn())) {
            jsonError(422, 'Rewriting with AI needs an AI API key. Add one on the System page first.');
        }
    }

    $postMode = in_array($body['post_mode'] ?? '', ['now', 'timeslot'], true) ? $body['post_mode'] : 'schedule';
    $scheduleTime = trim((string) ($body['schedule_time'] ?? ''));
    // Validate the timing before downloading any media, so a rejected
    // request never leaves a stray file behind.
    if ($postMode === 'schedule') {
        publer_resolveBaseTime($ctx, 'schedule', $scheduleTime);
    } elseif ($postMode === 'timeslot' && (empty($ctx['timeslots']) || $ctx['nextSlotPreview'] === null)) {
        jsonError(422, empty($ctx['timeslots'])
            ? 'No timeslots are set up. Ask an admin to add timeslots on the System page.'
            : 'No free timeslot available — every upcoming slot already has a post scheduled in Publer.');
    }

    // Media: downloaded from Publer's CDN into our upload folder, exactly
    // like an uploaded file, so the normal pipeline (pixel marking per page,
    // or one shared upload) applies. Facebook posts carry one photo/video.
    $stored = null;
    if (!empty($mediaItems)) {
        $mediaUrl = (string) ($mediaItems[0]['path'] ?? $mediaItems[0]['url'] ?? '');
        if ($mediaUrl === '') {
            jsonError(422, 'The post\'s media could not be found in Publer.');
        }
        $mediaError = '';
        $stored = storeRemoteMediaFile($mediaUrl, $mediaError);
        if ($stored === null) {
            jsonError(422, $mediaError);
        }
    }

    $baseTs = publer_resolveBaseTime($ctx, $postMode, $scheduleTime);
    $jobId  = createScheduleJob($ownerId, $userId, [
        'workspace_id'             => $workspaceId,
        'text'                     => $text,
        'comment'                  => $comments[0]['text'] ?? '',
        'use_mapped_post'          => false, // the original comments already carry their links
        'paraphrase'               => $paraphrase,
        'paraphrase_comment'       => $paraphraseComments,
        'paraphrase_comment2'      => $paraphraseComments,
        'paraphrase_tone'          => trim((string) ($body['paraphrase_tone'] ?? 'neutral')),
        'paraphrase_custom_prompt' => trim((string) ($body['paraphrase_custom_prompt'] ?? '')),
        'post_now'                 => $postMode === 'now',
        'use_timeslot'             => $postMode === 'timeslot',
        'base_ts'                  => $baseTs,
        'gap_seconds'              => publer_gapSeconds($ownerId, $isAdminUser, $body),
        'timeslot_cursor_key'      => $ctx['timeslotCursorKey'],
        'media_path'               => $stored['path'] ?? null,
        'media_mime'               => $stored['mime'] ?? null,
        'media_name'               => $stored['name'] ?? null,
        'account_ids'              => $accountIds,
        'comment_delay_minutes'    => $comments[0]['delay'] ?? 0,
        'comment2'                 => $comments[1]['text'] ?? '',
        'comment2_delay_minutes'   => $comments[1]['delay'] ?? 0,
        'mapped_links'             => [],
        'auto_delete_hours'        => resolveAutoDeleteHours($ownerId, $body),
        'add_pixels'               => array_key_exists('add_pixels', $body) ? !empty($body['add_pixels']) : defaultAddPixels($ownerId),
    ]);

    if (BACKGROUND_SCHEDULING) {
        triggerScheduleWorkerAsync();
    } else {
        $job = getScheduleJob($jobId, $ownerId);
        getDb()->prepare("UPDATE schedule_jobs SET state = 'processing', started_at = NOW() WHERE id = :id")->execute(['id' => $jobId]);
        runScheduleJob($job);
    }
    jsonResponse(['job_id' => $jobId], 202);
}

function scheduled_deleteAction(string $postId): void
{
    _scheduled_checkAccess();
    $userId      = currentUserId();
    $ownerId     = getSiteOwnerId($userId);
    $apiKey      = getPublerApiKey($userId);
    $workspaceId = fetchPublerWorkspaceId($userId);

    if (trim($postId) === '') {
        jsonError(422, 'Missing post id.');
    }
    // Publer's single-post delete. Never the bulk DELETE /posts for this:
    // when its post_ids don't arrive it deletes EVERY unpublished post of the
    // workspace. Accounts without the newer route get the bulk call with the
    // id in the query string (where Publer documents it), never without it.
    $res = publerRequest('DELETE', 'posts/' . rawurlencode($postId) . '/destroy_single', $apiKey, $workspaceId);
    if ($res['code'] === 404 && $res['error'] === '') {
        $res = publerRequest('DELETE', 'posts?post_ids[]=' . rawurlencode($postId), $apiKey, $workspaceId);
    }

    if ($res['error'] !== '') {
        jsonError(502, 'Could not reach Publer: ' . $res['error']);
    }
    if ($res['code'] >= 400) {
        $detail = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
        jsonError(502, 'Publer returned ' . $res['code'] . ': ' . $detail);
    }

    // If this was the most recently reserved timeslot for this workspace,
    // free it back up so the next "Use Timeslot" post can take it again —
    // otherwise the reserved-cursor mechanism (see publer.php/schedule
    // action) would keep skipping past a slot that's actually free again.
    $body        = jsonBody();
    $scheduledAt = trim((string) ($body['scheduled_at'] ?? ''));
    if ($scheduledAt !== '' && $workspaceId !== '') {
        $deletedTs  = strtotime($scheduledAt);
        $cursorKey  = 'timeslot_cursor_' . $workspaceId;
        $cursorTs   = (int) getSetting($ownerId, $cursorKey, '0');
        if ($deletedTs !== false && $cursorTs > 0 && $deletedTs - ($deletedTs % 60) === $cursorTs) {
            setSetting($ownerId, $cursorKey, (string) ($cursorTs - 60));
        }
    }

    publerCacheBump($workspaceId);
    jsonResponse(['message' => 'Post deleted from Publer queue.']);
}

function scheduled_editAction(string $postId): void
{
    _scheduled_checkAccess();
    $userId      = currentUserId();
    $apiKey      = getPublerApiKey($userId);
    $workspaceId = fetchPublerWorkspaceId($userId);
    $body        = jsonBody();

    $text = trim((string) ($body['text'] ?? ''));
    if ($text === '') {
        jsonError(422, 'Post text cannot be empty.');
    }

    // Publer's documented Update Post endpoint — text/title only; comments
    // aren't updatable through this API once a post is scheduled.
    $res = publerRequest('PUT', 'posts/' . rawurlencode($postId), $apiKey, $workspaceId, [
        'post' => ['text' => $text],
    ]);

    if ($res['error'] !== '') {
        jsonError(502, 'Could not reach Publer: ' . $res['error']);
    }
    if ($res['code'] >= 400) {
        $detail = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
        jsonError(502, 'Publer returned ' . $res['code'] . ': ' . $detail);
    }

    publerCacheBump($workspaceId);
    jsonResponse(['message' => 'Post updated.']);
}

function scheduled_rescheduleAction(string $postId): void
{
    _scheduled_checkAccess();
    $userId      = currentUserId();
    $apiKey      = getPublerApiKey($userId);
    $workspaceId = fetchPublerWorkspaceId($userId);
    $appTz       = getAppTimezone($userId);
    $body        = jsonBody();

    $newTime = trim((string) ($body['scheduled_at'] ?? ''));
    if ($newTime === '') {
        jsonError(422, 'Please provide a new scheduled time.');
    }

    try {
        $ts = (new DateTime($newTime, new DateTimeZone($appTz)))->getTimestamp();
    } catch (Exception $e) {
        $ts = false;
    }
    if ($ts === false || $ts <= time()) {
        jsonError(422, 'Please choose a future date and time.');
    }

    // ISO-8601 string Publer expects
    $scheduledAt = (new DateTime('@' . $ts))
        ->setTimezone(new DateTimeZone($appTz))
        ->format('c');

    // Publer's documented Reschedule Post endpoint.
    $res = publerRequest('PUT', 'posts/' . rawurlencode($postId) . '/reschedule', $apiKey, $workspaceId, [
        'post' => ['scheduled_at' => $scheduledAt],
    ]);

    // Older API versions without that route: the previous PATCH / PUT calls.
    if ($res['code'] === 405 || $res['code'] === 404) {
        $res = publerRequest('PATCH', 'posts/' . rawurlencode($postId), $apiKey, $workspaceId, [
            'scheduled_at' => $scheduledAt,
        ]);
    }
    if ($res['code'] === 405 || $res['code'] === 404) {
        $res = publerRequest('PUT', 'posts/' . rawurlencode($postId), $apiKey, $workspaceId, [
            'scheduled_at' => $scheduledAt,
        ]);
    }

    if ($res['error'] !== '') {
        jsonError(502, 'Could not reach Publer: ' . $res['error']);
    }
    if ($res['code'] >= 400) {
        $detail = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
        jsonError(502, 'Publer returned ' . $res['code'] . ': ' . $detail);
    }

    $formatted = (new DateTime('@' . $ts))
        ->setTimezone(new DateTimeZone($appTz))
        ->format('D, M j · H:i');

    publerCacheBump($workspaceId);
    jsonResponse(['message' => 'Post rescheduled to ' . $formatted . '.']);
}
