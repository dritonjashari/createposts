<?php
// Schedule Posts (Publer). Mirrors publer.php.

/** Shared setup used by both the context and the schedule action. */
function publer_loadContext(int $userId): array
{
    $ownerId = getSiteOwnerId($userId);
    ensurePublerMappingTable();

    $workspaceId       = fetchPublerWorkspaceId($userId);
    $facebookPages     = $workspaceId !== '' ? fetchPublerFacebookPages($userId, $workspaceId) : [];
    $appTz             = getAppTimezone($userId);
    $firstPageId       = (string) getSetting($ownerId, 'first_page_id_' . $workspaceId, '');
    $timeslots         = getTimeslotsForWorkspace($ownerId, $workspaceId);
    $timeslotCursorKey = 'timeslot_cursor_' . $workspaceId;

    $timeslotPageId = $facebookPages[0]['id'] ?? '';
    if ($firstPageId !== '') {
        foreach ($facebookPages as $p) {
            if (($p['id'] ?? '') === $firstPageId) { $timeslotPageId = $firstPageId; break; }
        }
    }
    $occupiedSlots   = $workspaceId !== '' ? fetchPublerOccupiedTimestamps($userId, $timeslotPageId) : [];
    $nextSlotPreview = !empty($timeslots)
        ? nextFreeTimeslotTimestamp($timeslots, max(time(), (int) getSetting($ownerId, $timeslotCursorKey, '0')), $occupiedSlots, $appTz)
        : null;

    // Put the configured "first page" at the top so it gets the base schedule time.
    if ($firstPageId !== '' && !empty($facebookPages)) {
        $first = null;
        $rest  = [];
        foreach ($facebookPages as $p) {
            if ($p['id'] === $firstPageId) { $first = $p; } else { $rest[] = $p; }
        }
        if ($first !== null) {
            $facebookPages = array_merge([$first], $rest);
        }
    }

    return [
        'ownerId'           => $ownerId,
        'workspaceId'       => $workspaceId,
        'facebookPages'     => $facebookPages,
        'appTz'             => $appTz,
        'timeslots'         => $timeslots,
        'timeslotCursorKey' => $timeslotCursorKey,
        'nextSlotPreview'   => $nextSlotPreview,
        'primaryPageId'     => $facebookPages[0]['id'] ?? '',
    ];
}

/** Gap between pages in seconds: admins choose it per post, editors always get the System default. */
function publer_gapSeconds(int $ownerId, bool $isAdmin, array $in): int
{
    if ($isAdmin) {
        $gapValue = max(0, (int) ($in['page_gap'] ?? 0));
        $gapUnit  = (($in['page_gap_unit'] ?? 'minutes') === 'hours') ? 'hours' : 'minutes';
    } else {
        $gapValue = (int) getSetting($ownerId, 'gap_value', '0');
        $gapUnit  = getSetting($ownerId, 'gap_unit', 'minutes');
    }
    return $gapValue * ($gapUnit === 'hours' ? 3600 : 60);
}


/** Check every chosen page belongs to the active workspace; stops with a 422 otherwise. */
function publer_requireWorkspacePages(array $accountIds, array $facebookPages): void
{
    if (empty($facebookPages)) {
        jsonError(422, 'Could not load the Facebook pages of this workspace from Publer. Please try again.');
    }
    $known = array_map(static fn ($p) => (string) $p['id'], $facebookPages);
    foreach ($accountIds as $aid) {
        if (!in_array($aid, $known, true)) {
            jsonError(422, 'One of the selected pages is not in the active workspace. Reload the page and try again.');
        }
    }
}


/**
 * When the first page posts, for "now" / "schedule" / "timeslot". Returns a
 * unix time (0 = post now) or stops with a 422. A timeslot is reserved
 * (cursor advanced) right here, so two quick submissions can't book the same
 * slot before either job has reached Publer.
 */
function publer_resolveBaseTime(array $ctx, string $postMode, string $scheduleTime): int
{
    if ($postMode === 'now') {
        return 0;
    }
    if ($postMode === 'timeslot') {
        if (empty($ctx['timeslots'])) {
            jsonError(422, 'No timeslots are set up. Ask an admin to add timeslots on the System page.');
        }
        if ($ctx['nextSlotPreview'] === null) {
            jsonError(422, 'No free timeslot available — every upcoming slot already has a post scheduled in Publer.');
        }
        $baseTs = (int) $ctx['nextSlotPreview'];
        setSetting($ctx['ownerId'], $ctx['timeslotCursorKey'], (string) $baseTs);
        return $baseTs;
    }
    if ($scheduleTime === '') {
        jsonError(422, 'Please choose the date and time to post.');
    }
    try {
        $baseTs = (new DateTime($scheduleTime, new DateTimeZone($ctx['appTz'])))->getTimestamp();
    } catch (Exception $e) {
        jsonError(422, 'Invalid schedule time.');
    }
    if ($baseTs < time() - 60) {
        jsonError(422, 'That time is in the past — please choose a future date and time.');
    }
    return $baseTs;
}


function publer_contextAction(): void
{
    requireLoginApi();
    $userId  = currentUserId();
    $ctx     = publer_loadContext($userId);
    $ownerId = $ctx['ownerId'];

    $editorsCanSchedule = getSetting($ownerId, 'editors_can_schedule', '1') === '1';
    $allowedWorkspaces  = getAllowedWorkspaces($userId);
    $wsName = '';
    foreach ($allowedWorkspaces as $w) { if ($w['id'] === $ctx['workspaceId']) { $wsName = $w['name']; break; } }

    jsonResponse([
        'editors_can_schedule'          => $editorsCanSchedule,
        'workspaces'                    => $allowedWorkspaces,
        'active_workspace_id'           => $ctx['workspaceId'],
        'active_workspace_name'         => $wsName,
        'facebook_pages'                => $ctx['facebookPages'],
        'sites'                         => getWordPressSitesForUser($ownerId),
        'mappings'                      => getPublerMappings($ownerId),
        'is_admin'                      => isAdmin(),
        'timezone'                      => $ctx['appTz'],
        'default_gap_value'             => (int) getSetting($ownerId, 'gap_value', '0'),
        'default_gap_unit'              => getSetting($ownerId, 'gap_unit', 'minutes'),
        'default_use_mapped'            => getSetting($ownerId, 'default_use_mapped', '1') === '1',
        'mapped_link_target'            => getSetting($ownerId, 'mapped_link_target', 'comment1'),
        'default_paraphrase'            => getSetting($ownerId, 'default_paraphrase_social', '0') === '1',
        'default_paraphrase_comment'    => getSetting($ownerId, 'default_paraphrase_comment', '1') === '1',
        'default_paraphrase_comment2'   => getSetting($ownerId, 'default_paraphrase_comment2', '1') === '1',
        'default_comment_delay_minutes' => (int) getSetting($ownerId, 'comment_delay_minutes', '0'),
        'default_comment2_delay_minutes' => (int) getSetting($ownerId, 'comment2_delay_minutes', '0'),
        'image_format'                  => (string) getSetting($ownerId, 'image_format', ''),
        'default_auto_delete_hours'     => defaultAutoDeleteHours($ownerId),
        'default_add_pixels'            => defaultAddPixels($ownerId),
        'timeslots'                     => $ctx['timeslots'],
        'next_timeslot_preview'         => $ctx['nextSlotPreview'],
        'next_timeslot_preview_formatted' => $ctx['nextSlotPreview'] !== null ? formatInTimezone($ctx['nextSlotPreview'], $ctx['appTz']) : null,
        'primary_page_id'               => $ctx['primaryPageId'],
    ]);
}

function publer_scheduleAction(): void
{
    requireLoginApi();
    $userId      = currentUserId();
    $isAdminUser = isAdmin();
    $apiKey      = getPublerApiKey($userId);

    $ctx           = publer_loadContext($userId);
    $ownerId       = $ctx['ownerId'];
    $editorsCanPickPages = getSetting($ownerId, 'editors_can_schedule', '1') === '1';

    $workspaceId   = $ctx['workspaceId'];
    $facebookPages = $ctx['facebookPages'];
    $appTz         = $ctx['appTz'];
    $timeslots     = $ctx['timeslots'];
    $mappings      = getPublerMappings($ownerId);

    $postText      = trim($_POST['text'] ?? '');
    $scheduleTime  = trim($_POST['schedule_time'] ?? '');
    $comment       = trim($_POST['comment'] ?? '');
    $comment2      = trim($_POST['comment2'] ?? '');
    $useMappedPost = !empty($_POST['use_mapped_post']);
    $paraphrase    = !empty($_POST['paraphrase']);
    $paraphraseComment = !empty($_POST['paraphrase_comment']); // independent of $paraphrase
    $paraphraseComment2 = !empty($_POST['paraphrase_comment2']); // independent of both above
    $commentDelayMinutes = max(0, (int) ($_POST['comment_delay_minutes'] ?? 0));
    $comment2DelayMinutes = max(0, (int) ($_POST['comment2_delay_minutes'] ?? 0));
    $mappedLinkTarget = getSetting($ownerId, 'mapped_link_target', 'comment1');
    $autoDeleteHours = resolveAutoDeleteHours($ownerId, $_POST);
    $autoDelete      = publerAutoDeleteCallback($autoDeleteHours);
    $paraphraseTone  = trim($_POST['paraphrase_tone'] ?? 'neutral');
    $paraphraseCustomPrompt = trim($_POST['paraphrase_custom_prompt'] ?? '');
    $addPixels     = isset($_POST['add_pixels']) ? $_POST['add_pixels'] === '1' : defaultAddPixels($ownerId);
    $postMode      = in_array($_POST['post_mode'] ?? '', ['now', 'timeslot'], true) ? $_POST['post_mode'] : 'schedule';
    $postNow       = ($postMode === 'now');
    $useTimeslot   = ($postMode === 'timeslot');
    $gapSecs       = publer_gapSeconds($ownerId, $isAdminUser, $_POST);

    if ($isAdminUser || $editorsCanPickPages) {
        $accountIds = array_values(array_filter(array_map(
            static fn ($id) => trim((string) $id),
            (array) ($_POST['account_ids'] ?? [])
        ), static fn ($id) => $id !== ''));
    } else {
        // Editors without page-picking access can still schedule — just always
        // to every page in the workspace, never a hand-picked subset. Ignore
        // whatever account_ids were submitted so this can't be bypassed by
        // calling the API directly.
        $accountIds = array_values(array_map(static fn ($p) => (string) $p['id'], $facebookPages));
    }

    // A default-on paraphrase toggle with nothing typed into that field
    // shouldn't require an AI key or trigger a paraphrase call — only check
    // for sites/fields that actually have text to rewrite.
    $needsOpenAi = $paraphrase
        || ($paraphraseComment && $comment !== '')
        || ($paraphraseComment2 && $comment2 !== '');

    $hasOpenAiKey = false;
    if ($needsOpenAi) {
        $stmt = getDb()->prepare('SELECT openai_api_key FROM users WHERE id = :id');
        $stmt->execute(['id' => $ownerId]);
        $hasOpenAiKey = !empty($stmt->fetchColumn());
    }

    if ($workspaceId === '') {
        jsonError(422, 'Could not resolve your Publer workspace. Check that the API key is valid.');
    }
    if ($postText === '' || empty($accountIds) || (!$postNow && !$useTimeslot && $scheduleTime === '')) {
        jsonError(422, ($postNow || $useTimeslot)
            ? 'Please write the post and select at least one Facebook page.'
            : 'Please write the post, choose a time, and select at least one Facebook page.');
    }
    publer_requireWorkspacePages($accountIds, $facebookPages);
    if ($useTimeslot && empty($timeslots)) {
        jsonError(422, 'No timeslots are set up. Ask an admin to add timeslots on the System page.');
    }
    if ($useTimeslot && $ctx['nextSlotPreview'] === null) {
        jsonError(422, 'No free timeslot available — every upcoming slot already has a post scheduled in Publer.');
    }
    if ($useTimeslot && $ctx['primaryPageId'] !== '' && !in_array($ctx['primaryPageId'], $accountIds, true)) {
        jsonError(422, 'Timeslot posting applies to the first page — select it, or choose Schedule / Post Now.');
    }
    if ($needsOpenAi && !$hasOpenAiKey) {
        jsonError(422, 'Paraphrasing needs an AI API key. Add one on the System page first.');
    }

    // Validate the time before storing the upload, so a bad time doesn't
    // leave an orphaned media file behind.
    if (!$postNow && !$useTimeslot) {
        publer_resolveBaseTime($ctx, 'schedule', $scheduleTime);
    }

    $uploadError = '';
    $storedMedia = storeUploadedMediaFile($_FILES['media'] ?? [], $uploadError);
    if ($uploadError !== '') {
        jsonError(422, $uploadError);
    }

    // Reserves the timeslot immediately, not after the job actually runs —
    // see publer_resolveBaseTime().
    $baseTs = publer_resolveBaseTime($ctx, $postMode, $scheduleTime);

    if (BACKGROUND_SCHEDULING) {
        // Resolve each account's mapped-post link NOW, while "latest published
        // post" still means what the user saw on screen — never inside the
        // worker. A queued job can sit for a while before a worker picks it
        // up, and re-resolving "latest" at that later point would let a link
        // meant for this submission silently get swapped for whatever was
        // published even later, in the meantime (see runScheduleJob()).
        $mappedLinks = [];
        if ($useMappedPost) {
            $siteLinkCache = [];
            foreach ($accountIds as $aid) {
                if (empty($mappings[$aid])) {
                    continue; // this page isn't mapped to a site — no link to attach
                }
                $siteId = (int) $mappings[$aid];
                if (!array_key_exists($siteId, $siteLinkCache)) {
                    $siteLinkCache[$siteId] = getLatestAppPublishedLink($ownerId, $siteId, $userId);
                }
                $mappedLinks[$aid] = $siteLinkCache[$siteId];
            }
        }

        // Queue the work and hand off to the background worker, so the request
        // isn't held open through a slow multi-page schedule (paraphrase + media
        // upload + a Publer call per page). Progress is polled via GET publer/jobs/{id}.
        $jobId = createScheduleJob($ownerId, $userId, [
            'workspace_id'             => $workspaceId,
            'text'                     => $postText,
            'comment'                  => $comment,
            'use_mapped_post'          => $useMappedPost,
            'paraphrase'               => $paraphrase,
            'paraphrase_comment'       => $paraphraseComment,
            'paraphrase_comment2'      => $paraphraseComment2,
            'paraphrase_tone'          => $paraphraseTone,
            'paraphrase_custom_prompt' => $paraphraseCustomPrompt,
            'post_now'                 => $postNow,
            'use_timeslot'             => $useTimeslot,
            'base_ts'                  => $baseTs,
            'gap_seconds'              => $gapSecs,
            'timeslot_cursor_key'      => $ctx['timeslotCursorKey'],
            'media_path'               => $storedMedia['path'] ?? null,
            'media_mime'               => $storedMedia['mime'] ?? null,
            'media_name'               => $storedMedia['name'] ?? null,
            'account_ids'              => $accountIds,
            'comment_delay_minutes'    => $commentDelayMinutes,
            'comment2'                 => $comment2,
            'comment2_delay_minutes'   => $comment2DelayMinutes,
            'mapped_links'             => $mappedLinks,
            'auto_delete_hours'        => $autoDeleteHours,
            'add_pixels'               => $addPixels,
        ]);

        triggerScheduleWorkerAsync(); // start now if possible; cron is the backstop

        jsonResponse(['job_id' => $jobId], 202);
    }

    $isVideo   = $storedMedia !== null && str_starts_with($storedMedia['mime'], 'video/');
    $isPhoto   = $storedMedia !== null && str_starts_with($storedMedia['mime'], 'image/');
    $mediaKind = $isVideo ? 'video' : ($isPhoto ? 'photo' : '');

    // A video is uploaded once and shared by every page (nothing to randomize
    // there). A photo gets its own freshly-marked, separately-uploaded copy
    // PER PAGE below, so every Facebook page ends up with a differently-
    // hashed image instead of every page sharing one Publer media id/file.
    $networkBase = ['type' => 'status'];
    if ($isVideo) {
        $media = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $uploadError, false);
        if ($uploadError !== '') {
            jsonError(422, $uploadError);
        }
        $mediaObj = ['id' => (string) $media['id']];
        if (!empty($media['thumbnails'])) {
            $mediaObj['thumbnails']        = $media['thumbnails'];
            $mediaObj['default_thumbnail'] = 0;
        }
        $networkBase['type']    = 'video';
        $networkBase['media']   = [$mediaObj];
        $networkBase['details'] = ['type' => 'reel'];
    } elseif ($isPhoto && !$addPixels) {
        // Pixels off: one plain upload shared by every page.
        $media = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $uploadError, false);
        if ($uploadError !== '') {
            jsonError(422, $uploadError);
        }
        $networkBase['type']  = 'photo';
        $networkBase['media'] = [['id' => (string) $media['id'], 'type' => 'image']];
    } elseif ($isPhoto) {
        $networkBase['type'] = 'photo'; // 'media' is attached per page below
    }

    $pageNames = [];
    foreach ($facebookPages as $p) {
        $pageNames[$p['id']] = $p['name'];
    }

    $latestCache    = [];
    $mappingNotes   = [];
    $paraphraseFail = 0;
    $pageCountTotal = count($accountIds);
    $pages          = [];

    foreach ($accountIds as $i => $aid) {
        $pageFailed = false;

        $pageText = $postText;
        if ($paraphrase) {
            $rew = paraphraseWithOpenAI($ownerId, $postText, $paraphraseTone, $pageNames[$aid] ?? null, $i, $pageCountTotal, $paraphraseCustomPrompt);
            if (!empty($rew['success']) && !empty($rew['text'])) {
                $pageText = $rew['text'];
            } else {
                $pageFailed = true;
            }
        }

        // Per-page first comment: its own AI rewrite, independently switched
        // on/off from the post text above (same tone) — never touched unless
        // this option is specifically on.
        $pageCommentText = $comment;
        if ($paraphraseComment && $comment !== '') {
            $rewC = paraphraseWithOpenAI($ownerId, $comment, $paraphraseTone, $pageNames[$aid] ?? null, $i, $pageCountTotal, $paraphraseCustomPrompt);
            if (!empty($rewC['success']) && !empty($rewC['text'])) {
                $pageCommentText = $rewC['text'];
            } else {
                $pageFailed = true;
            }
        }

        $pageComment2Text = $comment2;
        if ($paraphraseComment2 && $comment2 !== '') {
            $rewC2 = paraphraseWithOpenAI($ownerId, $comment2, $paraphraseTone, $pageNames[$aid] ?? null, $i, $pageCountTotal, $paraphraseCustomPrompt);
            if (!empty($rewC2['success']) && !empty($rewC2['text'])) {
                $pageComment2Text = $rewC2['text'];
            } else {
                $pageFailed = true;
            }
        }

        if ($pageFailed) {
            $paraphraseFail++;
        }

        $mappedLink = null;
        if ($useMappedPost && !empty($mappings[$aid])) {
            $siteId = (int) $mappings[$aid];
            if (!array_key_exists($siteId, $latestCache)) {
                $latestCache[$siteId] = getLatestAppPublishedLink($ownerId, $siteId, $userId);
            }
            $mappedLink = $latestCache[$siteId];
            if ($mappedLink === null) {
                $mappingNotes[] = $aid;
            }
        }
        // Which comment gets the mapped-post link is admin-configurable
        // (System page); the other comment is left as its own plain text.
        $pageComment  = ($useMappedPost && $mappedLinkTarget !== 'comment2')
            ? buildFirstComment($pageCommentText, $mappedLink) : $pageCommentText;
        $pageComment2 = ($useMappedPost && $mappedLinkTarget === 'comment2')
            ? buildFirstComment($pageComment2Text, $mappedLink) : $pageComment2Text;

        $account = ['id' => $aid];
        if (!$postNow) {
            $account['scheduled_at'] = date('c', $baseTs + ($i * $gapSecs));
        }
        // No "delay" field exists on a comment (that's AutoShare-only) —
        // timing is expressed as an "age" condition instead (Publer's Post
        // Callbacks API).
        $commentEntries = [];
        if ($pageComment !== '') {
            $entry = ['text' => $pageComment];
            if ($commentDelayMinutes > 0) {
                $entry['conditions'] = [
                    'relation' => 'AND',
                    'clauses'  => ['age' => ['duration' => $commentDelayMinutes, 'unit' => 'Minute']],
                ];
            }
            $commentEntries[] = $entry;
        }
        if ($pageComment2 !== '') {
            $entry2 = ['text' => $pageComment2];
            if ($comment2DelayMinutes > 0) {
                $entry2['conditions'] = [
                    'relation' => 'AND',
                    'clauses'  => ['age' => ['duration' => $comment2DelayMinutes, 'unit' => 'Minute']],
                ];
            }
            $commentEntries[] = $entry2;
        }
        if (!empty($commentEntries)) {
            $account['comments'] = $commentEntries;
        }
        if ($autoDelete !== null) {
            $account['delete'] = $autoDelete;
        }

        // Give this page its own freshly-marked copy of the image (random
        // position, opacity 0.8) — a fresh Publer upload per page, not a
        // shared media id, so every page's photo has a different file hash.
        $pageMedia = null;
        if ($isPhoto && $addPixels) {
            $up = publerUploadStoredMedia($storedMedia, $apiKey, $workspaceId, $uploadError, true, 25);
            if ($uploadError !== '') {
                jsonError(422, $uploadError);
            }
            $pageMedia = ['id' => (string) $up['id'], 'type' => 'image'];
        }

        $pages[] = ['text' => $pageText, 'account' => $account, 'media' => $pageMedia];
    }

    // Every page gets its own post (and its own API call) — Publer only honors
    // one scheduled_at per post, so several accounts sharing one post object
    // would silently lose everyone's time but the first.
    $payloads = [];
    foreach ($pages as $pg) {
        $net         = $networkBase;
        $net['text'] = $pg['text'];
        if ($pg['media'] !== null) {
            $net['media'] = [$pg['media']];
        }
        $payloads[]  = ['bulk' => ['state' => 'scheduled', 'posts' => [[
            'networks' => ['facebook' => $net],
            'accounts' => [$pg['account']],
        ]]]];
    }

    $endpoint     = $postNow ? 'posts/schedule/publish' : 'posts/schedule';
    $connError    = '';
    $anyAccepted  = false;
    $failMessages = [];

    foreach ($payloads as $pl) {
        $res = publerRequest('POST', $endpoint, $apiKey, $workspaceId, $pl);
        if ($res['error'] !== '') {
            $connError = $res['error'];
            continue;
        }
        if ($res['code'] === 200 || $res['code'] === 201) {
            $anyAccepted = true;
            $jobId = $res['data']['job_id'] ?? '';
            if ($jobId !== '') {
                $jobStatus     = publerRequest('GET', 'job_status/' . $jobId, $apiKey, $workspaceId);
                $jobFailures   = $jobStatus['data']['payload']['failures'] ?? null;
                if (!empty($jobFailures) && is_array($jobFailures)) {
                    foreach ($jobFailures as $postFails) {
                        foreach ((array) $postFails as $f) {
                            $failMessages[] = ($f['account_name'] ?? 'A page') . ': ' . ($f['message'] ?? 'failed');
                        }
                    }
                }
            }
        } else {
            $detail         = is_array($res['data']) ? json_encode($res['data']) : $res['raw'];
            $failMessages[] = 'HTTP ' . $res['code'] . ': ' . $detail;
        }
    }
    $failMessages = array_values(array_unique($failMessages));

    if (!$anyAccepted && $connError !== '') {
        jsonError(502, 'Connection failed: ' . $connError);
    }
    if (!empty($failMessages)) {
        jsonError(502, 'Publer reported a problem — ' . implode(' | ', $failMessages));
    }

    // The timeslot cursor was already advanced above, right when $baseTs was
    // decided (see the useTimeslot branch further up).
    publerCacheBump($workspaceId);

    jsonResponse([
        'message'             => 'Scheduled successfully.',
        'page_count'          => $pageCountTotal,
        'media_kind'          => $mediaKind,
        'base_timestamp'      => $baseTs,
        'gap_seconds'         => $gapSecs,
        'paraphrase_failures' => $paraphraseFail,
        'mapping_notes'       => $mappingNotes,
    ]);
}

function publer_jobStatusAction(string $jobId): void
{
    requireLoginApi();
    kickStalledWorkers();
    $ownerId = getSiteOwnerId(currentUserId());
    $job     = getScheduleJob((int) $jobId, $ownerId);
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
