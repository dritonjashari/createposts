import { Link } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import type { JobAlert } from '../../api/dashboard';
import { retryPublishJob } from '../../api/publish';
import Icon from '../ui/Icon';
import { formatDateTime } from '../../lib/datetime';

interface Props {
  publishAlerts: JobAlert[];
  scheduleAlerts: JobAlert[];
  timeZone: string;
}

/** Failed jobs from the last 7 days that still need a decision. Renders nothing when all is well. */
export default function AttentionCard({ publishAlerts, scheduleAlerts, timeZone }: Props) {
  const queryClient = useQueryClient();
  const retry = useMutation({
    mutationFn: (id: number) => retryPublishJob(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      queryClient.invalidateQueries({ queryKey: ['jobs-status'] });
    },
  });

  const alerts = [
    ...publishAlerts.map((a) => ({ ...a, kind: 'publish' as const })),
    ...scheduleAlerts.map((a) => ({ ...a, kind: 'schedule' as const })),
  ].sort((a, b) => (a.finished_at < b.finished_at ? 1 : -1));

  if (alerts.length === 0) return null;

  return (
    <div className="card card-alert">
      <h2>
        <Icon name="alert" size={17} style={{ verticalAlign: '-3px', marginRight: 6, color: 'var(--danger-fg)' }} />
        Needs attention <small>({alerts.length})</small>
      </h2>
      <ul className="list">
        {alerts.map((a) => (
          <li key={`${a.kind}-${a.id}`} className="attention-row">
            <span className={`badge ${a.kind === 'publish' ? 'badge-type-publish' : 'badge-type-schedule'}`}>
              {a.kind === 'publish' ? 'Website' : 'Facebook'}
            </span>
            <span className="attention-text">
              <strong>{(a.title ?? a.text ?? '').slice(0, 70) || 'Untitled'}</strong>
              <span className="small-text">
                {a.fail_count} of {a.total} {a.kind === 'publish' ? 'site' : 'page'}
                {a.total === 1 ? '' : 's'} failed · {formatDateTime(a.finished_at, timeZone)}
              </span>
            </span>
            {a.kind === 'publish' ? (
              <button type="button" className="btn-small btn-secondary" disabled={retry.isPending} onClick={() => retry.mutate(a.id)}>
                <Icon name="refresh" size={14} /> Retry failed
              </button>
            ) : (
              <Link className="btn-small btn-secondary button" to="/jobs-status">
                Open in Jobs Status
              </Link>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
