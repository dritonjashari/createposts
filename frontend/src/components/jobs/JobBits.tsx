import type { ReactNode } from 'react';
import { formatDateTime, timeAgo } from '../../lib/datetime';

export interface ResultLike {
  success: boolean;
  pending?: boolean;
  retried?: boolean;
  retried_as_job_id?: number;
  message?: string;
  name?: string;
}

type State = 'queued' | 'processing' | 'done' | 'error';

export function isRunning(state: State): boolean {
  return state === 'queued' || state === 'processing';
}

/** Status badge for a whole job. */
export function JobStatus({ state, failCount, runningLabel }: { state: State; failCount: number; runningLabel: string }) {
  if (state === 'queued') return <span className="job-status is-queued">Waiting to start</span>;
  if (state === 'processing') return <span className="job-status is-running"><span className="spinner" aria-hidden="true" />{runningLabel}</span>;
  if (failCount > 0) return <span className="job-status is-failed">{failCount} failed</span>;
  return <span className="job-status is-done">✓ Done</span>;
}

/** "submitted 5m ago · finished 4m ago · by Ana", with exact times on hover. */
export function JobMeta({ createdAt, finishedAt, by, progress }: { createdAt: string; finishedAt: string | null; by?: string | null; progress: ReactNode }) {
  return (
    <div className="job-meta">
      <span>{progress}</span>
      <span title={formatDateTime(createdAt)}>submitted {timeAgo(createdAt)}</span>
      {finishedAt && <span title={formatDateTime(finishedAt)}>finished {timeAgo(finishedAt)}</span>}
      {by && <span>by {by}</span>}
    </div>
  );
}

/** One line per site/page with its own outcome. */
export function JobResults({ results, okLabel, extra }: { results: Record<string, ResultLike>; okLabel: string; extra?: (id: string, r: ResultLike) => ReactNode }) {
  const entries = Object.entries(results ?? {});
  if (entries.length === 0) return null;
  return (
    <ul className="job-results">
      {entries.map(([id, r]) => {
        let status: ReactNode;
        if (r.success) status = <span className="job-result-ok">✓ {okLabel}</span>;
        else if (r.pending) status = <span className="job-result-pending">⏳ Processing…</span>;
        else if (r.retried) status = <span className="job-result-pending">↻ Retried{r.retried_as_job_id ? ` (job #${r.retried_as_job_id})` : ''}</span>;
        else status = <span className="job-result-fail">✕ {r.message ?? 'Failed'}</span>;
        return (
          <li className="job-result-item" key={id}>
            <span className="job-result-name">{r.name ?? `#${id}`}</span>
            <span className="job-result-right">
              {status}
              {extra?.(id, r)}
            </span>
          </li>
        );
      })}
    </ul>
  );
}
