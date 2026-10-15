import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  deleteScheduledPost,
  editScheduledPost,
  getScheduledPosts,
  reschedulePost,
  type QueueState,
  type ScheduledPost,
} from '../api/scheduled';
import { getPublerContext, type PublerContext } from '../api/publer';
import type { FbPage } from '../api/types';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import PageHeader from '../components/ui/PageHeader';
import Icon from '../components/ui/Icon';
import RepostDialog from '../components/queue/RepostDialog';
import { formatDateTime, toDateTimeInput } from '../lib/datetime';

// ─── Single post ───────────────────────────────────────────────────────────

function PostRow({
  post,
  timeZone,
  onChanged,
  onRepost,
}: {
  post: ScheduledPost;
  timeZone: string;
  onChanged: (msg: string) => void;
  onRepost: (post: ScheduledPost) => void;
}) {
  const isPublished = post.state.startsWith('published');
  const [mode, setMode] = useState<'idle' | 'reschedule' | 'edit' | 'confirm-delete'>('idle');
  const [newTime, setNewTime] = useState(() => toDateTimeInput(post.scheduled_at, timeZone));
  const [editText, setEditText] = useState(post.text);
  const [expanded, setExpanded] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);
  const [deleted, setDeleted] = useState(false);
  const fail = (fallback: string) => (err: unknown) => setRowError(err instanceof ApiError ? err.message : fallback);

  const deleteMutation = useMutation({
    mutationFn: () => deleteScheduledPost(post.id, post.scheduled_at),
    onSuccess: () => {
      setDeleted(true); // gone right away, not after the list reloads
      onChanged('Post deleted from the Publer queue.');
    },
    onError: fail('Delete failed.'),
  });
  const rescheduleMutation = useMutation({
    mutationFn: () => reschedulePost(post.id, newTime),
    onSuccess: (res) => {
      setMode('idle');
      onChanged(res.message);
    },
    onError: fail('Reschedule failed.'),
  });
  const editMutation = useMutation({
    mutationFn: () => editScheduledPost(post.id, editText),
    onSuccess: (res) => {
      setMode('idle');
      onChanged(res.message);
    },
    onError: fail('Could not save changes.'),
  });

  if (deleted) return null;

  return (
    <article className="sq-post-card">
      {post.has_media && (
        <div className="sq-post-thumb-wrap">
          {post.media_thumbnail ? (
            <img className="sq-post-thumb" src={post.media_thumbnail} alt="" loading="lazy" />
          ) : (
            <div className="sq-post-thumb sq-post-thumb-placeholder">
              <Icon name={post.media_type === 'video' ? 'video' : 'image'} />
            </div>
          )}
          {post.media_type === 'video' && <span className="sq-post-thumb-play">▶</span>}
        </div>
      )}

      <div className="sq-post-body">
        <div className="sq-post-accounts">
          {post.accounts.map((acc) => (
            <span key={acc.id} className="sq-account-chip">
              <span className="avatar">{(acc.name || '?').charAt(0).toUpperCase()}</span>
              <span className="sq-account-name">{acc.name || 'Unknown page'}</span>
              <span className="sq-account-time">{formatDateTime(acc.scheduled_at, timeZone)}</span>
            </span>
          ))}
        </div>

        {mode === 'edit' ? (
          <div className="field-group">
            <textarea rows={6} value={editText} onChange={(e) => setEditText(e.target.value)} />
            <div className="sq-reschedule-form">
              <button
                type="button"
                className="btn-small"
                disabled={editMutation.isPending || editText.trim() === ''}
                onClick={() => {
                  setRowError(null);
                  editMutation.mutate();
                }}
              >
                {editMutation.isPending ? 'Saving…' : 'Save text'}
              </button>
              <button
                type="button"
                className="btn-small btn-secondary"
                onClick={() => {
                  setEditText(post.text);
                  setMode('idle');
                  setRowError(null);
                }}
              >
                Cancel
              </button>
            </div>
          </div>
        ) : (
          <p
            className={`sq-post-text${expanded ? ' is-expanded' : ''}`}
            title={expanded ? undefined : 'Click to show the full text'}
            onClick={() => setExpanded((v) => !v)}
          >
            {post.text || <em>No text</em>}
          </p>
        )}

        {mode !== 'edit' && post.comments.length > 0 && (
          <div className="sq-post-comments">
            {post.comments.map((c, i) => (
              <p key={i} className="sq-post-comment" title={c}>
                <Icon name="comment" size={14} />
                <span>{c.length > 160 ? `${c.slice(0, 160)}…` : c}</span>
              </p>
            ))}
          </div>
        )}

        {rowError && <p className="sq-row-error">{rowError}</p>}

        {mode === 'reschedule' && (
          <div className="sq-reschedule-form">
            <input type="datetime-local" value={newTime} onChange={(e) => setNewTime(e.target.value)} />
            <button
              type="button"
              className="btn-small"
              disabled={rescheduleMutation.isPending || newTime === ''}
              onClick={() => {
                setRowError(null);
                rescheduleMutation.mutate();
              }}
            >
              {rescheduleMutation.isPending ? 'Saving…' : 'Save time'}
            </button>
            <button type="button" className="btn-small btn-secondary" onClick={() => { setMode('idle'); setRowError(null); }}>
              Cancel
            </button>
            <span className="field-hint">Times are in {timeZone}.</span>
          </div>
        )}

        {mode === 'confirm-delete' && (
          <div className="sq-confirm-row">
            <span>Delete this post from Publer?</span>
            <button
              type="button"
              className="btn-small btn-danger"
              disabled={deleteMutation.isPending}
              onClick={() => {
                setRowError(null);
                deleteMutation.mutate();
              }}
            >
              {deleteMutation.isPending ? 'Deleting…' : 'Yes, delete'}
            </button>
            <button type="button" className="btn-small btn-secondary" onClick={() => { setMode('idle'); setRowError(null); }}>
              Cancel
            </button>
          </div>
        )}
      </div>

      {mode === 'idle' && (
        <div className="sq-post-actions">
          <button type="button" className="btn-small" onClick={() => onRepost(post)} title="Post this to other pages of the workspace">
            <Icon name="repost" size={15} /> Repost
          </button>
          {isPublished ? (
            post.post_link && (
              <a className="btn-small btn-secondary button" href={post.post_link} target="_blank" rel="noreferrer">
                <Icon name="external" size={15} /> View on Facebook
              </a>
            )
          ) : (
            <>
              <button type="button" className="btn-small btn-secondary" onClick={() => { setEditText(post.text); setMode('edit'); setRowError(null); }}>
                <Icon name="edit" size={15} /> Edit
              </button>
              <button
                type="button"
                className="btn-small btn-secondary"
                onClick={() => {
                  setNewTime(toDateTimeInput(post.scheduled_at, timeZone));
                  setMode('reschedule');
                  setRowError(null);
                }}
              >
                <Icon name="clock" size={15} /> Reschedule
              </button>
              <button type="button" className="btn-small btn-danger" onClick={() => { setMode('confirm-delete'); setRowError(null); }}>
                <Icon name="trash" size={15} /> Delete
              </button>
            </>
          )}
        </div>
      )}
    </article>
  );
}

// ─── Page ──────────────────────────────────────────────────────────────────

export default function ScheduledPostsPage() {
  const queryClient = useQueryClient();
  const [state, setState] = useState<QueueState>('scheduled');
  const [page, setPage] = useState(1);
  const [filterPageId, setFilterPageId] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [flashMsg, setFlashMsg] = useState<string | null>(null);
  const [reposting, setReposting] = useState<ScheduledPost | null>(null);

  // Debounce the search box so typing doesn't fire a request per keystroke.
  useEffect(() => {
    const t = setTimeout(() => {
      setSearch(searchInput);
      setPage(1);
    }, 400);
    return () => clearTimeout(t);
  }, [searchInput]);

  const { data: context } = useQuery({ queryKey: ['publer-context'], queryFn: getPublerContext, staleTime: 60000 });
  const { data, error, refetch, isFetching } = useQuery({
    queryKey: ['scheduled-posts', state, page, filterPageId, search, dateFrom, dateTo],
    queryFn: () => getScheduledPosts({ state, page, accountId: filterPageId, search, dateFrom, dateTo }),
    staleTime: 0,
    // Show a failed or timed-out Publer read right away (Refresh tries again):
    // automatic retries only repeated the wait and spent more of the team's
    // Publer limit.
    retry: false,
  });

  const hasActiveFilters = filterPageId !== '' || search !== '' || dateFrom !== '' || dateTo !== '';
  const timeZone = context?.timezone ?? 'UTC';
  const posts = data?.state === state ? data.posts : [];
  const pages: FbPage[] = data?.pages ?? context?.facebook_pages ?? [];
  const total = data?.state === state ? data.total : 0;
  const loadingFirst = !data || data.state !== state;
  const hasNext = data?.state === state && (data.total_pages !== null ? page < data.total_pages : data.has_more);

  function clearFilters() {
    setFilterPageId('');
    setSearchInput('');
    setSearch('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  }

  function flash(msg: string) {
    setFlashMsg(msg);
    window.setTimeout(() => setFlashMsg(null), 5000);
  }

  function changed(msg: string) {
    flash(msg);
    queryClient.invalidateQueries({ queryKey: ['scheduled-posts'] });
    queryClient.invalidateQueries({ queryKey: ['publer-context'] });
  }

  function switchTab(next: QueueState) {
    if (next === state) return;
    setState(next);
    setPage(1);
  }

  return (
    <div className="container container-wide">
      <PageHeader
        title="Post Queue"
        subtitle="Everything scheduled in Publer, plus your published history — edit, reschedule, delete, or repost a post that did well to other pages."
        actions={
          <button type="button" className="btn-secondary" disabled={isFetching} onClick={() => void refetch()}>
            <Icon name="refresh" size={16} /> {isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />

      {flashMsg && <Alert kind="success">{flashMsg}</Alert>}
      {error && <Alert kind="danger">{error instanceof ApiError ? error.message : 'Could not load the queue.'}</Alert>}
      {!error && data?.warning && <Alert kind="warning">{data.warning}</Alert>}

      <div className="tabs" role="tablist">
        <button type="button" role="tab" aria-selected={state === 'scheduled'} className={`tab${state === 'scheduled' ? ' is-active' : ''}`} onClick={() => switchTab('scheduled')}>
          <Icon name="schedule" size={16} /> Scheduled
          {state === 'scheduled' && data?.state === 'scheduled' && <span className="count">{total}</span>}
        </button>
        <button type="button" role="tab" aria-selected={state === 'published'} className={`tab${state === 'published' ? ' is-active' : ''}`} onClick={() => switchTab('published')}>
          <Icon name="send" size={16} /> Published
          {state === 'published' && data?.state === 'published' && <span className="count">{total.toLocaleString()}</span>}
        </button>
      </div>

      <div className="filter-bar">
        <div className="field grow">
          <label htmlFor="q-search">Search</label>
          <div className="search-input">
            <Icon name="search" size={16} />
            <input id="q-search" type="search" placeholder="Search post text or comments…" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} />
          </div>
        </div>
        <div className="field fixed" style={{ flexBasis: 220 }}>
          <label htmlFor="q-page">Page</label>
          <select id="q-page" value={filterPageId} onChange={(e) => { setFilterPageId(e.target.value); setPage(1); }}>
            <option value="">All pages</option>
            {pages.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </div>
        <div className="field fixed">
          <label htmlFor="q-from">From</label>
          <input id="q-from" type="date" value={dateFrom} max={dateTo || undefined} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} />
        </div>
        <div className="field fixed">
          <label htmlFor="q-to">To</label>
          <input id="q-to" type="date" value={dateTo} min={dateFrom || undefined} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} />
        </div>
        {hasActiveFilters && (
          <button type="button" className="btn-ghost" onClick={clearFilters}>
            <Icon name="x" size={16} /> Clear
          </button>
        )}
      </div>

      {!loadingFirst && (
        <div className="filter-meta">
          <span>
            {state === 'scheduled'
              ? `${total} post${total === 1 ? '' : 's'} scheduled${hasActiveFilters ? ' (filtered)' : ''}`
              : `${total.toLocaleString()} published post${total === 1 ? '' : 's'}${hasActiveFilters ? ' (filtered)' : ''} · newest first`}
          </span>
        </div>
      )}

      {loadingFirst && error ? null /* the error above says what happened — not "nothing scheduled" */ : loadingFirst ? (
        <div className="sq-list" aria-busy="true" aria-label="Fetching posts from Publer">
          {[0, 1, 2].map((i) => (
            <div className="sq-post-card skeleton-card" key={i}>
              <div className="skeleton" style={{ width: 84, height: 84 }} />
              <div className="sq-post-body">
                <div className="skeleton" style={{ width: '35%', height: 14 }} />
                <div className="skeleton" style={{ width: '95%', height: 12 }} />
                <div className="skeleton" style={{ width: '80%', height: 12 }} />
              </div>
            </div>
          ))}
        </div>
      ) : posts.length === 0 ? (
        <div className="card">
          <div className="empty-state">
            <div className="empty-state-icon"><Icon name={state === 'scheduled' ? 'schedule' : 'send'} /></div>
            <strong>{hasActiveFilters ? 'No posts match these filters' : state === 'scheduled' ? 'Nothing scheduled right now' : 'No published posts yet'}</strong>
            <span className="small-text">
              {hasActiveFilters ? 'Try clearing the filters.' : state === 'scheduled' ? 'Posts you schedule appear here.' : 'Once posts go live they show up here, ready to repost.'}
            </span>
          </div>
        </div>
      ) : (
        <div className="sq-list">
          {posts.map((post) => (
            <PostRow key={post.id} post={post} timeZone={timeZone} onChanged={changed} onRepost={setReposting} />
          ))}
        </div>
      )}

      {(page > 1 || hasNext) && (
        <div className="pagination">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            <Icon name="chevronLeft" size={16} /> Previous
          </button>
          <span className="page-info">
            Page {page}
            {data?.total_pages ? ` of ${data.total_pages}` : ''}
          </span>
          <button type="button" disabled={!hasNext} onClick={() => setPage((p) => p + 1)}>
            Next <Icon name="chevronRight" size={16} />
          </button>
        </div>
      )}

      {reposting && context && (
        <RepostDialog
          post={reposting}
          context={context as PublerContext}
          onClose={() => setReposting(null)}
          onDone={() => {
            setReposting(null);
            changed('Repost finished — the new posts are in the Scheduled tab.');
          }}
        />
      )}
    </div>
  );
}
