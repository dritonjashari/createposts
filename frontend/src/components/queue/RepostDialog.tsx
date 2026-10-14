import { useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { repostPost, type ScheduledPost } from '../../api/scheduled';
import type { PublerContext } from '../../api/publer';
import { ApiError } from '../../api/client';
import Alert from '../Alert';
import Modal from '../ui/Modal';
import Toggle from '../ui/Toggle';
import Segmented from '../ui/Segmented';
import ScheduleJobProgress from '../schedule/ScheduleJobProgress';
import { formatDateTime, toDateTimeInput } from '../../lib/datetime';

type PostMode = 'now' | 'schedule' | 'timeslot';

interface Props {
  post: ScheduledPost;
  context: PublerContext;
  onClose: () => void;
  /** Called after the repost job finished and the user closes the dialog. */
  onDone: () => void;
}

/**
 * Repost a post (e.g. one that performed well) to other Facebook pages of
 * the same workspace, with the same per-page options as a fresh schedule.
 */
export default function RepostDialog({ post, context, onClose, onDone }: Props) {
  const originIds = post.accounts.map((a) => a.id);
  const pages = context.facebook_pages;
  const hasTimeslots = context.timeslots.length > 0 && context.next_timeslot_preview !== null;
  const isPhoto = post.has_media && post.media_type !== 'video';

  const [accountIds, setAccountIds] = useState<string[]>(() => pages.filter((p) => !originIds.includes(p.id)).map((p) => p.id));
  const [postMode, setPostMode] = useState<PostMode>(hasTimeslots ? 'timeslot' : 'schedule');
  const [scheduleTime, setScheduleTime] = useState(() => toDateTimeInput(new Date(Date.now() + 60 * 60 * 1000), context.timezone));
  const [pageGap, setPageGap] = useState(context.default_gap_value);
  const [pageGapUnit, setPageGapUnit] = useState<'minutes' | 'hours'>(context.default_gap_unit);
  const [addPixels, setAddPixels] = useState(context.default_add_pixels);
  const [includeComments, setIncludeComments] = useState(true);
  const [paraphrase, setParaphrase] = useState(false);
  const [paraphraseComments, setParaphraseComments] = useState(false);
  const [autoDelete, setAutoDelete] = useState(context.default_auto_delete_hours > 0);
  const [autoDeleteHours, setAutoDeleteHours] = useState(context.default_auto_delete_hours > 0 ? context.default_auto_delete_hours : 48);
  const [jobId, setJobId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const allSelected = accountIds.length === pages.length && pages.length > 0;
  const originNames = useMemo(() => post.accounts.map((a) => a.name || a.id).join(', '), [post.accounts]);

  const mutation = useMutation({
    mutationFn: () =>
      repostPost(post.id, {
        account_ids: accountIds,
        post_mode: postMode,
        schedule_time: postMode === 'schedule' ? scheduleTime : undefined,
        page_gap: pageGap,
        page_gap_unit: pageGapUnit,
        add_pixels: addPixels,
        include_comments: includeComments,
        paraphrase,
        paraphrase_comments: includeComments && paraphraseComments,
        auto_delete: autoDelete,
        auto_delete_hours: autoDelete ? autoDeleteHours : undefined,
      }),
    onSuccess: (res) => setJobId(res.job_id),
    onError: (err) => setError(err instanceof ApiError ? err.message : 'The post could not be reposted. Please try again.'),
  });

  function toggle(id: string) {
    setAccountIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  if (jobId !== null) {
    return (
      <Modal title="Reposting" onClose={onDone}>
        <ScheduleJobProgress jobId={jobId} onDone={onDone} doneLabel="Close" bare />
      </Modal>
    );
  }

  const canSubmit = accountIds.length > 0 && (postMode !== 'schedule' || scheduleTime !== '') && !mutation.isPending;

  return (
    <Modal
      title="Repost to other pages"
      onClose={onClose}
      busy={mutation.isPending}
      wide
      footer={
        <>
          <span className="small-text spacer">
            {accountIds.length} page{accountIds.length === 1 ? '' : 's'} selected
          </span>
          <button type="button" className="btn-secondary" onClick={onClose} disabled={mutation.isPending}>
            Cancel
          </button>
          <button
            type="button"
            disabled={!canSubmit}
            onClick={() => {
              setError(null);
              mutation.mutate();
            }}
          >
            {mutation.isPending ? 'Preparing…' : `Repost to ${accountIds.length} page${accountIds.length === 1 ? '' : 's'}`}
          </button>
        </>
      }
    >
      {error && <Alert kind="danger">{error}</Alert>}

      <div className="repost-preview">
        {post.media_thumbnail && <img src={post.media_thumbnail} alt="" />}
        <div style={{ minWidth: 0 }}>
          <div className="repost-preview-text">{post.text || <em>No text</em>}</div>
          <div className="repost-preview-meta">
            From {originNames || 'unknown page'} · {formatDateTime(post.scheduled_at, context.timezone)}
            {post.comments.length > 0 && ` · ${post.comments.length} comment${post.comments.length === 1 ? '' : 's'}`}
          </div>
        </div>
      </div>

      <div>
        <div className="select-all-row">
          <span className="modal-section-title" style={{ margin: 0 }}>Post to</span>
          <label>
            <input
              type="checkbox"
              checked={allSelected}
              onChange={() => setAccountIds(allSelected ? [] : pages.map((p) => p.id))}
            />
            Select all
          </label>
        </div>
        {pages.length === 0 ? (
          <p className="small-text">No Facebook pages found in this workspace.</p>
        ) : (
          <div className="page-picker">
            {pages.map((p) => {
              const checked = accountIds.includes(p.id);
              return (
                <label key={p.id} className={`page-option${checked ? ' is-checked' : ''}`}>
                  <input type="checkbox" checked={checked} onChange={() => toggle(p.id)} />
                  <span className="item-name">{p.name}</span>
                  {originIds.includes(p.id) && <span className="tag">original</span>}
                  {!p.connected && <span className="status-pill status-down">offline</span>}
                </label>
              );
            })}
          </div>
        )}
      </div>

      <div>
        <div className="modal-section-title">When</div>
        <Segmented<PostMode>
          ariaLabel="When to post"
          value={postMode}
          onChange={setPostMode}
          options={[
            ...(hasTimeslots ? [{ value: 'timeslot' as const, label: 'Next free timeslot' }] : []),
            { value: 'schedule', label: 'Pick a time' },
            { value: 'now', label: 'Post now' },
          ]}
        />
        {postMode === 'schedule' && (
          <div className="field-group" style={{ marginTop: 10 }}>
            <input type="datetime-local" value={scheduleTime} onChange={(e) => setScheduleTime(e.target.value)} />
            <span className="field-hint">Times are in {context.timezone}.</span>
          </div>
        )}
        {postMode === 'timeslot' && (
          <p className="field-hint" style={{ marginTop: 8 }}>
            Next free slot: <strong>{context.next_timeslot_preview_formatted}</strong>
          </p>
        )}
        {context.is_admin && accountIds.length > 1 && postMode !== 'now' && (
          <div className="field-group" style={{ marginTop: 12 }}>
            <label>Gap between pages</label>
            <div className="gap-row" style={{ maxWidth: 320 }}>
              <input type="number" min={0} value={pageGap} onChange={(e) => setPageGap(Math.max(0, Number(e.target.value) || 0))} />
              <select value={pageGapUnit} onChange={(e) => setPageGapUnit(e.target.value as 'minutes' | 'hours')}>
                <option value="minutes">Minutes</option>
                <option value="hours">Hours</option>
              </select>
            </div>
          </div>
        )}
      </div>

      <div>
        <div className="modal-section-title">Options</div>
        <div className="toggle-list">
          <Toggle
            checked={isPhoto && addPixels}
            onChange={setAddPixels}
            disabled={!isPhoto}
            label="Add random white pixels to the image"
            hint={isPhoto ? 'Every page gets its own slightly different copy of the photo.' : 'Only applies to photo posts.'}
          />
          {post.comments.length > 0 && (
            <Toggle
              checked={includeComments}
              onChange={setIncludeComments}
              label={`Keep the comment${post.comments.length === 1 ? '' : 's'}`}
              hint="Posted with the same delay as on the original."
            />
          )}
          <Toggle checked={paraphrase} onChange={setParaphrase} label="Rewrite the text with AI" hint="A different version for every page." />
          {includeComments && post.comments.length > 0 && (
            <Toggle checked={paraphraseComments} onChange={setParaphraseComments} label="Rewrite the comments with AI" />
          )}
          <Toggle checked={autoDelete} onChange={setAutoDelete} label="Auto-delete from Facebook later" />
          {autoDelete && (
            <div className="gap-row" style={{ maxWidth: 320, alignItems: 'center', marginLeft: 50 }}>
              <input
                type="number"
                min={1}
                max={8760}
                value={autoDeleteHours}
                onChange={(e) => setAutoDeleteHours(Math.min(8760, Math.max(1, Math.floor(Number(e.target.value) || 1))))}
              />
              <span className="small-text" style={{ whiteSpace: 'nowrap' }}>hours after posting</span>
            </div>
          )}
        </div>
      </div>
    </Modal>
  );
}
