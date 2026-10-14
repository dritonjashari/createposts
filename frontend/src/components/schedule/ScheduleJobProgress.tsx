import { useQuery } from '@tanstack/react-query';
import { getScheduleJob } from '../../api/publer';

interface Props {
  jobId: number;
  onDone: () => void;
  doneLabel?: string;
  /** Render without the surrounding card (e.g. inside a dialog). */
  bare?: boolean;
}

export default function ScheduleJobProgress({ jobId, onDone, doneLabel = 'Schedule another', bare }: Props) {
  const { data } = useQuery({
    queryKey: ['schedule-job', jobId],
    queryFn: () => getScheduleJob(jobId),
    refetchInterval: (query) => {
      const state = query.state.data?.state;
      return state === 'done' || state === 'error' ? false : 1500;
    },
  });

  const total = data?.total ?? 0;
  const done = data?.done ?? 0;
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  const finished = data?.state === 'done' || data?.state === 'error';
  const results = data?.results ? Object.entries(data.results) : [];
  const failed = results.filter(([, r]) => !r.success).length;

  const body = (
    <>
      <h2>{!finished ? 'Scheduling…' : failed > 0 ? `Done — ${failed} page${failed === 1 ? '' : 's'} failed` : 'All done'}</h2>
      <div className="progress-bar" aria-label="Progress">
        <div className="progress-bar-fill" style={{ width: `${finished ? 100 : Math.max(pct, 4)}%` }} />
      </div>
      <p className="small-text">
        {done} / {total || '…'} pages
        {finished ? ' — complete. You can find these posts in the Post Queue.' : ' — you can keep working; this continues in the background.'}
      </p>

      {results.length > 0 && (
        <ul className="job-results">
          {results.map(([aid, r]) => (
            <li className="job-result-item" key={aid}>
              <span>{r.name ?? `Page #${aid}`}</span>
              {r.success ? (
                <span className="job-result-ok">✓ {r.message ?? 'Scheduled'}</span>
              ) : (
                <span className="job-result-fail">✕ {r.message ?? 'Failed'}</span>
              )}
            </li>
          ))}
        </ul>
      )}

      {finished && (
        <button type="button" onClick={onDone} style={{ marginTop: 14 }}>
          {doneLabel}
        </button>
      )}
    </>
  );

  return bare ? <div>{body}</div> : <div className="card">{body}</div>;
}
