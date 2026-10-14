import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { getDashboard, type DashboardDays, type EditorDashboard } from '../api/dashboard';
import { useAuth } from '../context/AuthContext';
import LoadError from '../components/LoadError';
import PageHeader from '../components/ui/PageHeader';
import Segmented from '../components/ui/Segmented';
import Icon from '../components/ui/Icon';
import StatTile, { countDelta, pointsDelta } from '../components/dashboard/StatTile';
import ActivityChart from '../components/dashboard/ActivityChart';
import AttentionCard from '../components/dashboard/AttentionCard';
import RecentPostsCard from '../components/dashboard/RecentPostsCard';
import { formatDateTime } from '../lib/datetime';

function isOverdue(due: string | null | undefined): boolean {
  return !!due && new Date(`${due}T23:59:59`).getTime() < Date.now();
}

export default function EditorDashboardPage() {
  const { user } = useAuth();
  const [days, setDays] = useState<DashboardDays>(7);
  const { data, isLoading, error, refetch, isFetching } = useQuery({ queryKey: ['dashboard', 'editor', days], queryFn: () => getDashboard(days) });
  const dashboard = data as EditorDashboard | undefined;
  const stale = isFetching && dashboard !== undefined && dashboard.days !== days;

  const periodLabel = `prior ${days} days`;
  const t = dashboard?.totals;
  const tz = dashboard?.timezone ?? 'UTC';
  const overdue = dashboard?.open_tasks.filter((x) => isOverdue(x.due_date)).length ?? 0;

  return (
    <div className="container">
      <PageHeader
        title="Dashboard"
        subtitle={`Welcome back, ${user?.name ?? ''}. Here's your work at a glance.`}
        actions={
          <>
            <Segmented<'7' | '30'>
              ariaLabel="Period"
              value={String(days) as '7' | '30'}
              onChange={(v) => setDays(Number(v) as DashboardDays)}
              options={[{ value: '7', label: '7 days' }, { value: '30', label: '30 days' }]}
            />
            <Link className="button btn-secondary hide-sm" to="/publish"><Icon name="publish" size={16} /> Publish</Link>
            <Link className="button hide-sm" to="/schedule"><Icon name="schedule" size={16} /> Schedule</Link>
          </>
        }
      />

      {!dashboard && error ? (
        <LoadError error={error} onRetry={() => void refetch()} />
      ) : isLoading || !dashboard || !t ? (
        <p className="subtitle">Loading…</p>
      ) : (
        <div className={stale ? 'is-stale' : undefined}>
          <div className="stats-grid">
            <StatTile
              label={`My website posts · ${dashboard.days} days`}
              value={t.web.toLocaleString()}
              icon="publish"
              accent="#2563eb"
              delta={countDelta(t.web, t.web_prev, periodLabel)}
              sub={`${t.web_today} today`}
            />
            <StatTile
              label={`My Facebook posts · ${dashboard.days} days`}
              value={t.social.toLocaleString()}
              icon="send"
              accent="#ea580c"
              delta={countDelta(t.social, t.social_prev, periodLabel)}
              sub={`${t.social_today} today`}
            />
            <StatTile
              label="My success rate"
              value={t.success_rate === null ? '—' : `${t.success_rate}%`}
              icon="check"
              accent="#059669"
              delta={pointsDelta(t.success_rate, t.success_rate_prev, periodLabel)}
              sub={
                t.failed > 0 ? (
                  <Link to="/jobs-status">{t.failed} failed — review</Link>
                ) : t.success_rate === null ? (
                  'Nothing posted yet'
                ) : (
                  'No failures'
                )
              }
            />
            <StatTile
              label="Open tasks"
              value={dashboard.open_task_count}
              icon="tasks"
              accent="#7c3aed"
              sub={overdue > 0 ? <span className="text-warn">{overdue} overdue</span> : dashboard.open_task_count > 0 ? 'None overdue' : 'All done'}
            />
          </div>

          <AttentionCard publishAlerts={dashboard.publish_alerts} scheduleAlerts={dashboard.schedule_alerts} timeZone={tz} />

          <div className="card">
            <h2>My activity</h2>
            <p className="field-note" style={{ marginTop: -10, marginBottom: 12 }}>Your posts per day, last {dashboard.days} days · {tz}</p>
            <ActivityChart series={dashboard.series} />
          </div>

          <div className="dash-grid">
            <div className="card">
              <h2>Your open tasks <small>({dashboard.open_task_count})</small></h2>
              {dashboard.open_tasks.length === 0 ? (
                <p className="subtitle">Nothing outstanding right now.</p>
              ) : (
                <ul className="list">
                  {dashboard.open_tasks.map((task) => (
                    <li key={task.id} className="attention-row">
                      <span className="attention-text">
                        <Link to={`/tasks/${task.id}`}><strong>{task.title}</strong></Link>
                        <span className="small-text">from {task.admin_name}</span>
                      </span>
                      {task.due_date && (
                        <span className={`task-due${isOverdue(task.due_date) ? ' overdue' : ''}`}>
                          due {new Date(`${task.due_date}T12:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="card">
              <h2>
                Scheduled Posts <small>({dashboard.queue_count === null ? '—' : dashboard.queue_count.toLocaleString()} in Publer)</small>
              </h2>
              {dashboard.scheduled_posts.length === 0 ? (
                <p className="subtitle">Nothing scheduled right now.</p>
              ) : (
                <ul className="list">
                  {dashboard.scheduled_posts.map((p) => (
                    <li key={p.id} className="attention-row">
                      <span className="attention-text">
                        <strong className="clamp-1" title={p.text}>{p.text || 'No text'}</strong>
                        <span className="small-text">{p.account || 'Unknown page'} · {formatDateTime(p.scheduled_at, tz)}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              <Link className="btn-small btn-secondary button" to="/queue" style={{ marginTop: 12 }}>Open the Post Queue</Link>
            </div>
          </div>

          <RecentPostsCard title="Your recent website posts" showPoster={false} timeZone={tz} />
        </div>
      )}
    </div>
  );
}
