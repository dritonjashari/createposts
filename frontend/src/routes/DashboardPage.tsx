import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { getDashboard, type AdminDashboard, type DashboardDays, type SiteRow } from '../api/dashboard';
import { approveUser } from '../api/users';
import { useAuth } from '../context/AuthContext';
import LoadError from '../components/LoadError';
import PageHeader from '../components/ui/PageHeader';
import Segmented from '../components/ui/Segmented';
import Icon from '../components/ui/Icon';
import StatTile, { countDelta, pointsDelta } from '../components/dashboard/StatTile';
import ActivityChart from '../components/dashboard/ActivityChart';
import AttentionCard from '../components/dashboard/AttentionCard';
import RecentPostsCard from '../components/dashboard/RecentPostsCard';
import { timeAgo } from '../lib/datetime';

/** A website with no post for this long gets flagged. */
const QUIET_AFTER_HOURS = 48;

function quietFor(site: SiteRow): number | null {
  if (!site.last_post) return null;
  const hours = (Date.now() - new Date(site.last_post).getTime()) / 3_600_000;
  return hours >= QUIET_AFTER_HOURS ? Math.floor(hours / 24) : null;
}

function isOverdue(due: string | null | undefined): boolean {
  return !!due && new Date(`${due}T23:59:59`).getTime() < Date.now();
}

export default function DashboardPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [days, setDays] = useState<DashboardDays>(7);
  const { data, isLoading, error, refetch, isFetching } = useQuery({ queryKey: ['dashboard', 'admin', days], queryFn: () => getDashboard(days) });
  const dashboard = data as AdminDashboard | undefined;
  // While another period loads, keep showing the previous numbers, dimmed.
  const stale = isFetching && dashboard !== undefined && dashboard.days !== days;

  const approveMutation = useMutation({
    mutationFn: (id: number) => approveUser(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
  });

  const periodLabel = `prior ${days} days`;
  const t = dashboard?.totals;
  const tz = dashboard?.timezone ?? 'UTC';
  const overdue = dashboard?.open_tasks.filter((x) => isOverdue(x.due_date)).length ?? 0;
  const maxMember = Math.max(1, ...(dashboard?.team ?? []).map((m) => m.web + m.social));

  return (
    <div className="container">
      <PageHeader
        title="Dashboard"
        subtitle={`Welcome back, ${user?.name ?? ''}. Here's how your team is doing.`}
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
              label={`Website posts · ${dashboard.days} days`}
              value={t.web.toLocaleString()}
              icon="publish"
              accent="#2563eb"
              delta={countDelta(t.web, t.web_prev, periodLabel)}
              sub={`${t.web_today} today`}
            />
            <StatTile
              label={`Facebook posts · ${dashboard.days} days`}
              value={t.social.toLocaleString()}
              icon="send"
              accent="#ea580c"
              delta={countDelta(t.social, t.social_prev, periodLabel)}
              sub={`${t.social_today} today`}
            />
            <StatTile
              label="Success rate"
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
              label="Queued on Facebook"
              value={dashboard.queue_count === null ? '—' : dashboard.queue_count.toLocaleString()}
              icon="schedule"
              accent="#7c3aed"
              sub={<Link to="/queue">Open the Post Queue</Link>}
            />
          </div>

          <AttentionCard publishAlerts={dashboard.publish_alerts} scheduleAlerts={dashboard.schedule_alerts} timeZone={tz} />

          {dashboard.pending_users.length > 0 && (
            <div className="card card-warn">
              <h2>Pending approval <small>({dashboard.pending_users.length})</small></h2>
              <ul className="list">
                {dashboard.pending_users.map((u) => (
                  <li key={u.id} className="attention-row">
                    <span className="attention-text">
                      <strong>{u.name}</strong>
                      <span className="small-text">{u.email} · requested {timeAgo(u.created_at)}</span>
                    </span>
                    <button type="button" className="btn-small" disabled={approveMutation.isPending} onClick={() => approveMutation.mutate(u.id)}>
                      Approve
                    </button>
                    <Link className="btn-small btn-secondary button" to="/users">Review</Link>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="card">
            <h2>Activity</h2>
            <p className="field-note" style={{ marginTop: -10, marginBottom: 12 }}>Posts per day, last {dashboard.days} days · {tz}</p>
            <ActivityChart series={dashboard.series} />
          </div>

          <div className="dash-grid dash-grid-3-2">
            <div className="card">
              <h2>Team performance <small>· {dashboard.days} days</small></h2>
              {dashboard.team.length === 0 ? (
                <p className="subtitle">No team members yet — add one on the <Link to="/users">Users</Link> page.</p>
              ) : (
                <div className="table-wrap">
                  <table className="posts-table data-table">
                    <thead>
                      <tr>
                        <th>Member</th>
                        <th className="num-cell">Website</th>
                        <th className="num-cell">Facebook</th>
                        <th className="num-cell">Success</th>
                        <th>Last active</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboard.team.map((m) => (
                        <tr key={m.id}>
                          <td>
                            <div className="member-cell">
                              <span className="avatar avatar-sm">{m.name.charAt(0).toUpperCase()}</span>
                              <div className="member-text">
                                <span className="member-name">{m.name}</span>
                                <span className="member-bar" aria-hidden="true">
                                  <span style={{ width: `${((m.web + m.social) / maxMember) * 100}%` }} />
                                </span>
                              </div>
                            </div>
                          </td>
                          <td className="num-cell">{m.web}</td>
                          <td className="num-cell">{m.social}</td>
                          <td className="num-cell">
                            {m.success_rate === null ? '—' : (
                              <span className={m.failed > 0 ? 'text-warn' : undefined} title={m.failed > 0 ? `${m.failed} failed` : undefined}>
                                {m.success_rate}%
                              </span>
                            )}
                          </td>
                          <td className="muted-cell">{m.last_active ? timeAgo(m.last_active) : 'never'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="card">
              <h2>Websites <small>· {dashboard.days} days</small></h2>
              {dashboard.sites.length === 0 ? (
                <p className="subtitle">No websites connected — add one on the <Link to="/sites">Websites</Link> page.</p>
              ) : (
                <div className="table-wrap">
                  <table className="posts-table data-table">
                    <thead>
                      <tr>
                        <th>Website</th>
                        <th className="num-cell">Posts</th>
                        <th>Last post</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboard.sites.map((s) => {
                        const quiet = quietFor(s);
                        return (
                          <tr key={s.id}>
                            <td className="title-cell">
                              <a href={s.url} target="_blank" rel="noreferrer">{s.name}</a>
                            </td>
                            <td className="num-cell">{s.posts}</td>
                            <td className="muted-cell">
                              {s.last_post ? timeAgo(s.last_post) : 'never'}
                              {(quiet !== null || !s.last_post) && (
                                <span className="status-pill status-warn" style={{ marginLeft: 8 }}>
                                  {s.last_post ? `quiet ${quiet}d` : 'no posts'}
                                </span>
                              )}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          <div className="dash-grid">
            <div className="card">
              <h2>
                Open tasks <small>({dashboard.open_task_count}{overdue > 0 ? ` · ${overdue} overdue` : ''})</small>
              </h2>
              {dashboard.open_tasks.length === 0 ? (
                <p className="subtitle">Nothing outstanding — assign one on the <Link to="/tasks">Tasks</Link> page.</p>
              ) : (
                <ul className="list">
                  {dashboard.open_tasks.map((task) => (
                    <li key={task.id} className="attention-row">
                      <span className="attention-text">
                        <Link to={`/tasks/${task.id}`}><strong>{task.title}</strong></Link>
                        <span className="small-text">assigned to {task.assignee_name}</span>
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
              <h2>Recently finished tasks</h2>
              {dashboard.finished_tasks.length === 0 ? (
                <p className="subtitle">No finished tasks yet.</p>
              ) : (
                <ul className="list">
                  {dashboard.finished_tasks.map((task) => (
                    <li key={task.id} className="attention-row">
                      <span className="attention-text">
                        <Link to={`/tasks/${task.id}`}><strong>{task.title}</strong></Link>
                        <span className="small-text">
                          by {task.assignee_name}{task.completed_at ? ` · ${timeAgo(task.completed_at)}` : ''}
                        </span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          <RecentPostsCard title="Recent website posts" showPoster timeZone={tz} />
        </div>
      )}
    </div>
  );
}
