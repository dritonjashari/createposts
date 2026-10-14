import type { ReactNode } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { getPublishJob, retryPublishJob } from '../../api/publish';
import { ApiError } from '../../api/client';

interface Props {
  jobId: number;
  onDone: () => void;
  onRetried: (newJobId: number) => void;
}

export default function JobProgress({ jobId, onDone, onRetried }: Props) {
  const { data } = useQuery({
    queryKey: ['publish-job', jobId],
    queryFn: () => getPublishJob(jobId),
    refetchInterval: (query) => {
      const state = query.state.data?.state;
      return state === 'done' || state === 'error' ? false : 2000;
    },
  });

  const retryMutation = useMutation({
    mutationFn: () => retryPublishJob(jobId),
    onSuccess: (res) => onRetried(res.job_id),
  });

  if (!data) {
    return <p className="subtitle">Starting…</p>;
  }

  const pct = data.total > 0 ? Math.round((data.done / data.total) * 100) : 0;
  const finished = data.state === 'done' || data.state === 'error';
  const results = data.results ?? {};
  const anyFailed = Object.values(results).some((r) => !r.success && !r.pending);

  return (
    <div className="card">
      <h2>{finished ? 'Done' : 'Publishing…'}</h2>
      <div className="progress-bar">
        <div className="progress-bar-fill" style={{ width: `${pct}%` }} />
      </div>
      <p className="small-text">
        {data.done} / {data.total} sites{finished ? ' — complete' : ' — processing content…'}
      </p>

      {data.results && (
        <ul className="job-results">
          {Object.entries(data.results).map(([siteId, r]) => {
            let statusEl: ReactNode;
            if (r.success) {
              statusEl = <span className="job-result-ok">✓ Published</span>;
            } else if (r.pending) {
              statusEl = <span className="job-result-pending">⏳ Draft created — processing content…</span>;
            } else {
              statusEl = <span className="job-result-fail">✕ {r.message ?? 'Failed'}</span>;
            }
            return (
              <li className="job-result-item" key={siteId}>
                <span>{r.title ?? r.name ?? `Site #${siteId}`}</span>
                {statusEl}
              </li>
            );
          })}
        </ul>
      )}

      {!finished && data.results && Object.values(data.results).some((r) => r.pending) && (
        <p className="small-text" style={{ marginTop: 10 }}>
          Draft posts have been created on all sites. You can go to{' '}
          <strong>Schedule Posts</strong> now to schedule them — the content will
          finish updating in the background.
        </p>
      )}

      {finished && anyFailed && retryMutation.error && (
        <p className="sq-row-error">
          {retryMutation.error instanceof ApiError ? retryMutation.error.message : 'Could not retry.'}
        </p>
      )}

      {finished && (
        <div style={{ display: 'flex', gap: 10, marginTop: 12, flexWrap: 'wrap' }}>
          {anyFailed && (
            <button
              type="button"
              className="btn-secondary"
              disabled={retryMutation.isPending}
              onClick={() => retryMutation.mutate()}
            >
              {retryMutation.isPending ? 'Retrying…' : '↻ Retry failed sites'}
            </button>
          )}
          <button type="button" onClick={onDone}>
            Publish another
          </button>
        </div>
      )}
    </div>
  );
}
