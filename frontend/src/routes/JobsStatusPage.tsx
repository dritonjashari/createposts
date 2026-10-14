import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { getJobs, retryJob } from '../api/jobs';
import type { JobSummary } from '../api/types';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import PageHeader from '../components/ui/PageHeader';
import Icon from '../components/ui/Icon';
import { isRunning, JobMeta, JobResults, JobStatus } from '../components/jobs/JobBits';

function JobCard({ job, isAdmin, onChanged }: { job: JobSummary; isAdmin: boolean; onChanged: (msg?: string) => void }) {
  const [rowError, setRowError] = useState<string | null>(null);
  const isPublish = job.job_type === 'publish';
  const unit = isPublish ? 'site' : 'page';

  const retryMutation = useMutation({
    mutationFn: () => retryJob(job.job_type, job.id),
    onSuccess: () => onChanged(`Retrying the failed ${unit}s — the new job is at the top of the list.`),
    onError: (err) => setRowError(err instanceof ApiError ? err.message : 'Could not retry.'),
  });

  const finished = !isRunning(job.state);

  return (
    <article className="sq-post-card">
      <div className="sq-post-body">
        <div className="job-head">
          <span className={`badge badge-type-${job.job_type}`}>{isPublish ? 'Website' : 'Social'}</span>
          <strong className="job-title" title={job.label}>{job.label || 'Untitled'}</strong>
          <JobStatus state={job.state} failCount={job.fail_count} runningLabel={isPublish ? 'Publishing…' : 'Scheduling…'} />
        </div>
        <JobMeta
          createdAt={job.created_at}
          finishedAt={finished ? job.finished_at : null}
          by={isAdmin ? job.posted_by_name : null}
          progress={`${job.done}/${job.total} ${unit}${job.total === 1 ? '' : 's'}`}
        />
        <JobResults results={job.results} okLabel={isPublish ? 'Published' : 'Scheduled'} />
        {rowError && <p className="sq-row-error">{rowError}</p>}
        {finished && job.fail_count > 0 && (
          <div>
            <button type="button" className="btn-small" disabled={retryMutation.isPending} onClick={() => { setRowError(null); retryMutation.mutate(); }}>
              <Icon name="refresh" size={14} /> {retryMutation.isPending ? 'Retrying…' : `Retry ${job.fail_count} failed ${unit}${job.fail_count === 1 ? '' : 's'}`}
            </button>
          </div>
        )}
      </div>
    </article>
  );
}

export default function JobsStatusPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [flash, setFlash] = useState<string | null>(null);
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    queryKey: ['jobs-status', page],
    queryFn: () => getJobs(page),
    // Live progress while anything on the page is still running.
    refetchInterval: (q) => (q.state.data?.jobs.some((j) => isRunning(j.state)) ? 3000 : false),
  });

  function changed(msg?: string) {
    if (msg) {
      setFlash(msg);
      window.setTimeout(() => setFlash(null), 5000);
    }
    queryClient.invalidateQueries({ queryKey: ['jobs-status'] });
    queryClient.invalidateQueries({ queryKey: ['publish-jobs'] });
    queryClient.invalidateQueries({ queryKey: ['scheduled-posts'] });
    queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  }

  const jobs = data?.jobs ?? [];
  const isAdmin = data?.is_admin ?? false;
  const running = jobs.filter((j) => isRunning(j.state)).length;

  return (
    <div className="container container-wide">
      <PageHeader
        title="Jobs Status"
        subtitle="Every publish and schedule job running in the background — what's still processing, what failed, and a one-click retry for failures."
        actions={
          <button type="button" className="btn-secondary" disabled={isFetching} onClick={() => void refetch()}>
            <Icon name="refresh" size={16} /> {isFetching ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />
      {flash && <Alert kind="success">{flash}</Alert>}
      {error && !data && <Alert kind="danger">{error instanceof ApiError ? error.message : 'Could not load the jobs.'}</Alert>}
      {running > 0 && (
        <p className="filter-meta"><span><span className="spinner" aria-hidden="true" /> {running} job{running === 1 ? '' : 's'} running — this page updates by itself.</span></p>
      )}

      {isLoading && !data ? (
        <p className="subtitle">Loading…</p>
      ) : jobs.length === 0 && !error ? (
        <div className="card">
          <div className="empty-state">
            <div className="empty-state-icon"><Icon name="tasks" /></div>
            <strong>No background jobs yet</strong>
            <span className="small-text">
              Jobs appear here when you <Link to="/publish">publish to websites</Link> or <Link to="/schedule">schedule to social pages</Link>.
            </span>
          </div>
        </div>
      ) : (
        <div className="sq-list">
          {jobs.map((job) => (
            <JobCard key={`${job.job_type}-${job.id}`} job={job} isAdmin={isAdmin} onChanged={changed} />
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
