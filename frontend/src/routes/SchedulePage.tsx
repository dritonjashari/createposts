import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getPublerContext, schedulePost } from '../api/publer';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import ScheduleJobProgress from '../components/schedule/ScheduleJobProgress';
import ComposeSection from '../components/schedule/ComposeSection';
import MediaDropzoneCard from '../components/schedule/MediaDropzoneCard';
import WhenCard from '../components/schedule/WhenCard';
import SelectPagesCard from '../components/schedule/SelectPagesCard';
import { useSharedMedia } from '../context/SharedMediaContext';
import PageHeader from '../components/ui/PageHeader';

type PostMode = 'schedule' | 'now' | 'timeslot';

export default function SchedulePage() {
  const queryClient = useQueryClient();
  const { data: context, isLoading } = useQuery({ queryKey: ['publer-context'], queryFn: getPublerContext, staleTime: 0 });
  const { sharedFile } = useSharedMedia();

  const [text, setText] = useState('');
  const [paraphrase, setParaphrase] = useState(false);
  const [paraphraseComment, setParaphraseComment] = useState(false);
  const [paraphraseComment2, setParaphraseComment2] = useState(false);
  const [tone, setTone] = useState('neutral');
  const [customPrompt, setCustomPrompt] = useState('');
  const [comment, setComment] = useState('');
  const [commentDelayMinutes, setCommentDelayMinutes] = useState(0);
  const [showComment, setShowComment] = useState(false);
  const [comment2, setComment2] = useState('');
  const [comment2DelayMinutes, setComment2DelayMinutes] = useState(0);
  const [showComment2, setShowComment2] = useState(false);
  const [useMappedPost, setUseMappedPost] = useState(true);
  const [postMode, setPostMode] = useState<PostMode>('timeslot');
  const [scheduleTime, setScheduleTime] = useState('');
  const [pageGap, setPageGap] = useState(0);
  const [pageGapUnit, setPageGapUnit] = useState<'minutes' | 'hours'>('minutes');
  const [autoDelete, setAutoDelete] = useState(false);
  const [autoDeleteHours, setAutoDeleteHours] = useState(48);
  const [addPixels, setAddPixels] = useState(true);
  const [accountIds, setAccountIds] = useState<string[]>([]);
  const [media, setMedia] = useState<File | null>(() => sharedFile);
  const [mediaPreview, setMediaPreview] = useState<string | null>(() =>
    sharedFile ? URL.createObjectURL(sharedFile) : null
  );
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<string | null>(null);
  const [jobId, setJobId] = useState<number | null>(null);

  useEffect(() => {
    if (context) {
      setParaphrase(context.default_paraphrase);
      setParaphraseComment(context.default_paraphrase_comment);
      setParaphraseComment2(context.default_paraphrase_comment2);
      setCommentDelayMinutes(context.default_comment_delay_minutes);
      setComment2DelayMinutes(context.default_comment2_delay_minutes);
      setUseMappedPost(context.default_use_mapped);
      setPageGap(context.default_gap_value);
      setPageGapUnit(context.default_gap_unit);
      setAutoDelete(context.default_auto_delete_hours > 0);
      if (context.default_auto_delete_hours > 0) setAutoDeleteHours(context.default_auto_delete_hours);
      setAddPixels(context.default_add_pixels);
      setAccountIds(context.facebook_pages.map((p) => p.id));
    }
  }, [context]);

  const pages = context?.facebook_pages ?? [];
  const allSelected = pages.length > 0 && accountIds.length === pages.length;
  // Admins always see/pick pages; editors only when their admin has allowed it
  // on the System page. When not shown, editors still schedule — just to
  // every page in the workspace (accountIds already defaults to all of them).
  const showPagePicker = context?.is_admin || context?.editors_can_schedule === true;
  // Show timeslot option whenever a primary page is configured and timeslots exist.
  // next_timeslot_preview may still be null (all slots occupied); that's handled at submit time.
  const hasTimeslots =
    (context?.primary_page_id ?? '') !== '' &&
    (context?.timeslots?.length ?? 0) > 0;

  function toggleAccount(id: string) {
    setAccountIds((prev) => (prev.includes(id) ? prev.filter((a) => a !== id) : [...prev, id]));
  }

  function toggleSelectAll() {
    setAccountIds(allSelected ? [] : pages.map((p) => p.id));
  }

  function handleFile(file: File | null) {
    setMedia(file);
    setMediaPreview(file ? URL.createObjectURL(file) : null);
  }

  const scheduleMutation = useMutation({
    mutationFn: (formData: FormData) => schedulePost(formData),
    onSuccess: (res) => {
      setError(null);
      if (res.job_id) {
        setJobId(res.job_id);
        return;
      }
      // Synchronous fallback response (BACKGROUND_SCHEDULING disabled server-side).
      let msg = `${res.message ?? 'Scheduled.'} Scheduled to ${res.page_count ?? 0} page(s).`;
      if ((res.paraphrase_failures ?? 0) > 0) {
        msg += ` ${res.paraphrase_failures} page(s) could not be paraphrased and used the original text.`;
      }
      if ((res.mapping_notes?.length ?? 0) > 0) {
        msg += ` Note: ${res.mapping_notes!.length} page(s) had no recent post link to attach.`;
      }
      setResult(msg);
      setText('');
      setComment('');
      setComment2('');
      setMedia(null);
      setMediaPreview(null);
      queryClient.invalidateQueries({ queryKey: ['publer-context'] });
    },
    onError: (err) => {
      setResult(null);
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
    },
  });

  function resetForm() {
    setJobId(null);
    setResult(null);
    setText('');
    setComment('');
    setComment2('');
    setMedia(null);
    setMediaPreview(null);
    queryClient.invalidateQueries({ queryKey: ['publer-context'] });
  }

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (accountIds.length === 0) {
      setError('Please select at least one Facebook page.');
      return;
    }
    const fd = new FormData();
    fd.append('text', text);
    if (postMode === 'schedule') fd.append('schedule_time', scheduleTime);
    fd.append('comment', comment);
    if (comment !== '') fd.append('comment_delay_minutes', String(commentDelayMinutes));
    if (comment2 !== '') {
      fd.append('comment2', comment2);
      fd.append('comment2_delay_minutes', String(comment2DelayMinutes));
    }
    if (useMappedPost) fd.append('use_mapped_post', '1');
    if (paraphrase) fd.append('paraphrase', '1');
    if (paraphraseComment) fd.append('paraphrase_comment', '1');
    if (paraphraseComment2) fd.append('paraphrase_comment2', '1');
    if (paraphrase || paraphraseComment || paraphraseComment2) {
      fd.append('paraphrase_tone', tone);
      if (tone === 'custom') fd.append('paraphrase_custom_prompt', customPrompt);
    }
    fd.append('post_mode', postMode);
    fd.append('auto_delete', autoDelete ? '1' : '0');
    fd.append('add_pixels', addPixels ? '1' : '0');
    if (autoDelete) fd.append('auto_delete_hours', String(autoDeleteHours));
    if (context?.is_admin) {
      fd.append('page_gap', String(pageGap));
      fd.append('page_gap_unit', pageGapUnit);
    }
    accountIds.forEach((id) => fd.append('account_ids[]', id));
    if (media) fd.append('media', media);
    scheduleMutation.mutate(fd);
  }

  if (jobId !== null) {
    return (
      <div className="container container-wide">
        <PageHeader title="Schedule Posts" />
        <ScheduleJobProgress jobId={jobId} onDone={resetForm} />
      </div>
    );
  }

  return (
    <div className="container container-wide">
      <PageHeader
        title="Schedule Posts"
        subtitle={context ? `Workspace: ${context.active_workspace_name || 'None selected'} · ${pages.length} Facebook page${pages.length === 1 ? '' : 's'}` : 'Schedule a post to your Facebook pages via Publer.'}
      />
      {result && <Alert kind="success">{result}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      {isLoading ? (
        <p className="subtitle">Loading…</p>
      ) : (
        <form onSubmit={handleSubmit}>
          <div className="publish-layout">
            <div className="publish-main">
              <ComposeSection
                text={text}
                onTextChange={setText}
                paraphrase={paraphrase}
                onParaphraseChange={setParaphrase}
                paraphraseComment={paraphraseComment}
                onParaphraseCommentChange={setParaphraseComment}
                paraphraseComment2={paraphraseComment2}
                onParaphraseComment2Change={setParaphraseComment2}
                tone={tone}
                onToneChange={setTone}
                customPrompt={customPrompt}
                onCustomPromptChange={setCustomPrompt}
                useMappedPost={useMappedPost}
                onUseMappedPostChange={setUseMappedPost}
                mappedLinkTarget={context?.mapped_link_target ?? 'comment1'}
                showComment={showComment}
                onToggleShowComment={() => setShowComment((v) => !v)}
                comment={comment}
                onCommentChange={setComment}
                commentDelayMinutes={commentDelayMinutes}
                onCommentDelayMinutesChange={setCommentDelayMinutes}
                showComment2={showComment2}
                onToggleShowComment2={() => setShowComment2((v) => !v)}
                comment2={comment2}
                onComment2Change={setComment2}
                comment2DelayMinutes={comment2DelayMinutes}
                onComment2DelayMinutesChange={setComment2DelayMinutes}
              />

              <MediaDropzoneCard
                media={media}
                mediaPreview={mediaPreview}
                onFileChange={handleFile}
                addPixels={addPixels}
                onAddPixelsChange={setAddPixels}
              />

              <WhenCard
                postMode={postMode}
                onPostModeChange={setPostMode}
                hasTimeslots={hasTimeslots}
                scheduleTime={scheduleTime}
                onScheduleTimeChange={setScheduleTime}
                nextTimeslotPreview={context?.next_timeslot_preview_formatted}
                timezone={context?.timezone}
                isAdmin={!!context?.is_admin}
                pageGap={pageGap}
                onPageGapChange={setPageGap}
                pageGapUnit={pageGapUnit}
                onPageGapUnitChange={setPageGapUnit}
                autoDelete={autoDelete}
                onAutoDeleteChange={setAutoDelete}
                autoDeleteHours={autoDeleteHours}
                onAutoDeleteHoursChange={setAutoDeleteHours}
              />
            </div>

            <div className="publish-sidebar">
              <SelectPagesCard
                showPagePicker={!!showPagePicker}
                pages={pages}
                accountIds={accountIds}
                allSelected={allSelected}
                onToggleAccount={toggleAccount}
                onToggleSelectAll={toggleSelectAll}
                isPending={scheduleMutation.isPending}
              />
            </div>
          </div>
        </form>
      )}
    </div>
  );
}
