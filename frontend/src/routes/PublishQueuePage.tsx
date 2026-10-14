import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { getPublishJobPost, getPublishJobs, getPublishJobThumbnail, retryPublishJob, updatePublishJobPost } from '../api/publish';
import type { PublishJobSummary } from '../api/types';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import PageHeader from '../components/ui/PageHeader';
import Icon from '../components/ui/Icon';
import { isRunning, JobMeta, JobResults, JobStatus } from '../components/jobs/JobBits';

/** Thumbnail: recorded with the job, or looked up lazily (cached server-side) for older jobs. */
function JobThumb({ job }: { job: PublishJobSummary }) {
  const needsLookup = !job.featured_image_url && job.has_image && (job.edit_targets?.length ?? 0) > 0;
  const { data } = useQuery({
    queryKey: ['publish-job-thumb', job.id],
    queryFn: () => getPublishJobThumbnail(job.id),
    enabled: needsLookup,
    staleTime: Infinity,
    retry: false,
  });
  const url = job.featured_image_url ?? data?.url ?? null;
  if (!url && !job.has_image) return null;
  return (
    <div className="sq-post-thumb-wrap">
      {url ? (
        <img className="sq-post-thumb" src={url} alt="" loading="lazy" />
      ) : (
        <div className="sq-post-thumb sq-post-thumb-placeholder"><Icon name="image" /></div>
      )}
    </div>
  );
}

function EditPostForm({ jobId, siteId, siteName, onDone, onCancel }: { jobId: number; siteId: number; siteName: string; onDone: (msg: string) => void; onCancel: () => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['publish-job-post', jobId, siteId],
    queryFn: () => getPublishJobPost(jobId, siteId),
    staleTime: 0,
  });
  const [title, setTitle] = useState<string | null>(null);
  const [content, setContent] = useState<string | null>(null);
  const t = title ?? data?.title ?? '';
  const c = content ?? data?.content ?? '';

  const saveMutation = useMutation({
    mutationFn: () => updatePublishJobPost(jobId, siteId, t, c),
    onSuccess: (res) => onDone(`${res.message} (${siteName})`),
  });

  if (isLoading) {
    return <p className="small-text" style={{ marginTop: 10 }}>Opening the post on {siteName}…</p>;
  }
  if (error || !data) {
    return (
      <div className="sq-confirm-row">
        <p className="sq-row-error">{error instanceof ApiError ? error.message : `Could not load this post from ${siteName}.`}</p>
        <button type="button" className="btn-small btn-secondary" onClick={onCancel}>Close</button>
      </div>
    );
  }

  return (
    <div className="edit-panel">
      <div className="edit-panel-head">
        <strong>Editing on {siteName}</strong>
        {data.link && <a href={data.link} target="_blank" rel="noreferrer" className="small-text">View post <Icon name="external" size={13} /></a>}
      </div>
      <div className="stack">
        <div className="field-group">
          <label>Title</label>
          <input type="text" value={t} onChange={(e) => setTitle(e.target.value)} />
        </div>
        <div className="field-group">
          <label>Content</label>
          <textarea rows={10} value={c} onChange={(e) => setContent(e.target.value)} />
        </div>
      </div>
      {saveMutation.error && (
        <p className="sq-row-error">{saveMutation.error instanceof ApiError ? saveMutation.error.message : 'Could not save changes.'}</p>
      )}
      <div className="sq-reschedule-form" style={{ marginTop: 12 }}>
        <button type="button" className="btn-small" disabled={saveMutation.isPending || t.trim() === '' || c.trim() === ''} onClick={() => saveMutation.mutate()}>
          {saveMutation.isPending ? 'Saving…' : 'Save to WordPress'}
        </button>
        <button type="button" className="btn-small btn-secondary" onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

function JobCard({ job, isAdmin, onChanged }: { job: PublishJobSummary; isAdmin: boolean; onChanged: (msg?: string) => void }) {
  const [rowError, setRowError] = useState<string | null>(null);
  const [editing, setEditing] = useState<{ siteId: number; name: string } | null>(null);

  const retryMutation = useMutation({
    mutationFn: () => retryPublishJob(job.id),
    onSuccess: () => onChanged('Retrying the failed sites — the new job is at the top of the list.'),
    onError: (err) => setRowError(err instanceof ApiError ? err.message : 'Could not retry.'),
  });

  const finished = !isRunning(job.state);
  const postIdBySite = new Map((job.edit_targets ?? []).map((t) => [String(t.site_id), t]));

  return (
    <article className="sq-post-card">
      <JobThumb job={job} />
      <div className="sq-post-body">
        <div className="job-head">
          <strong className="job-title" title={job.title}>{job.title || 'Untitled'}</strong>
          <JobStatus state={job.state} failCount={job.fail_count} runningLabel="Publishing…" />
        </div>
        <JobMeta
          createdAt={job.created_at}
          finishedAt={finished ? job.finished_at : null}
          by={isAdmin ? job.posted_by_name : null}
          progress={`${job.done}/${job.total} site${job.total === 1 ? '' : 's'}`}
        />
        <JobResults
          results={job.results}
          okLabel="Published"
          extra={(id) => {
            const target = postIdBySite.get(id);
            return target && !editing ? (
              <button type="button" className="btn-small btn-ghost" onClick={() => setEditing({ siteId: target.site_id, name: target.name })}>
                <Icon name="edit" size={14} /> Edit
              </button>
            ) : null;
          }}
        />
        {rowError && <p className="sq-row-error">{rowError}</p>}
        {editing && (
          <EditPostForm
            jobId={job.id}
            siteId={editing.siteId}
            siteName={editing.name}
            onCancel={() => setEditing(null)}
            onDone={(msg) => {
              setEditing(null);
              onChanged(msg);
            }}
          />
        )}
        {finished && job.fail_count > 0 && !editing && (
          <div>
            <button type="button" className="btn-small" disabled={retryMutation.isPending} onClick={() => { setRowError(null); retryMutation.mutate(); }}>
              <Icon name="refresh" size={14} /> {retryMutation.isPending ? 'Retrying…' : `Retry ${job.fail_count} failed site${job.fail_count === 1 ? '' : 's'}`}
            </button>
          </div>
        )}
      </div>
    </article>
  );
}

export default function PublishQueuePage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [flash, setFlash] = useState<string | null>(null);
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['publish-jobs', page],
    queryFn: () => getPublishJobs(page),
    // Live progress while anything on the page is still running.
    refetchInterval: (q) => (q.state.data?.jobs.some((j) => isRunning(j.state)) ? 3000 : false),
  });

  function changed(msg?: string) {
    if (msg) {
      setFlash(msg);
      window.setTimeout(() => setFlash(null), 5000);
    }
    queryClient.invalidateQueries({ queryKey: ['publish-jobs'] });
    queryClient.invalidateQueries({ queryKey: ['jobs-status'] });
    queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  }

  const jobs = data?.jobs ?? [];
  const isAdmin = data?.is_admin ?? false;
  const running = jobs.filter((j) => isRunning(j.state)).length;

  return (
    <div className="container container-wide">
      <PageHeader
        title="Publish Queue"
        subtitle={
          isAdmin
            ? "Every article published to your websites — progress, failures, retries, and editing what's already live."
            : "Articles you've published to the websites — progress, failures, retries, and editing what's already live."
        }
        actions={
          <button type="button" className="btn-secondary" disabled={isFetching} onClick={() => void refetch()}>
            <Icon name="refresh" size={16} /> {isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />
      {flash && <Alert kind="success">{flash}</Alert>}
      {error && !data && <Alert kind="danger">{error instanceof ApiError ? error.message : 'Could not load the publish queue.'}</Alert>}
      {running > 0 && (
        <p className="filter-meta"><span><span className="spinner" aria-hidden="true" /> {running} job{running === 1 ? '' : 's'} running — this page updates by itself.</span></p>
      )}

      {isLoading && !data ? (
        <p className="subtitle">Loading…</p>
      ) : jobs.length === 0 && !error ? (
        <div className="card">
          <div className="empty-state">
            <div className="empty-state-icon"><Icon name="publish" /></div>
            <strong>Nothing published yet</strong>
            <span className="small-text"><Link to="/publish">Publish to Websites</Link> to get started.</span>
          </div>
        </div>
      ) : (
        <div className="sq-list">
          {jobs.map((job) => (
            <JobCard key={job.id} job={job} isAdmin={isAdmin} onChanged={changed} />
          ))}
        </div>
      )}

      {data && data.total_pages > 1 && (
        <div className="pagination">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}><Icon name="chevronLeft" size={16} /> Previous</button>
          <span className="page-info">Page {data.page} of {data.total_pages}</span>
          <button type="button" disabled={page >= data.total_pages} onClick={() => setPage((p) => p + 1)}>Next <Icon name="chevronRight" size={16} /></button>
        </div>
      )}
    </div>
  );
}
