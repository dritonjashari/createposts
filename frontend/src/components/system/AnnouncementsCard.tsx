import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createAnnouncement, deleteAnnouncement, getAnnouncements, type Announcement } from '../../api/announcements';
import { ApiError } from '../../api/client';

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function isActive(a: Announcement): boolean {
  const today = todayIso();
  return a.start_date <= today && today <= a.end_date;
}

export default function AnnouncementsCard() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['announcements'], queryFn: getAnnouncements });

  const [message, setMessage] = useState('');
  const [kind, setKind] = useState<Announcement['kind']>('info');
  const [startDate, setStartDate] = useState(todayIso());
  const [endDate, setEndDate] = useState(todayIso());
  const [targetUserId, setTargetUserId] = useState('');
  const [error, setError] = useState<string | null>(null);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['announcements'] });
    queryClient.invalidateQueries({ queryKey: ['announcements-active'] });
  }

  const createMutation = useMutation({
    mutationFn: () =>
      createAnnouncement({
        message,
        kind,
        start_date: startDate,
        end_date: endDate,
        target_user_id: targetUserId === '' ? null : Number(targetUserId),
      }),
    onSuccess: () => {
      setError(null);
      setMessage('');
      setKind('info');
      setStartDate(todayIso());
      setEndDate(todayIso());
      setTargetUserId('');
      invalidate();
    },
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Something went wrong.'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteAnnouncement(id),
    onSuccess: invalidate,
  });

  const announcements = data?.announcements ?? [];
  const teamMembers = data?.team_members ?? [];

  return (
    <div className="card">
      <h2>System Announcements</h2>
      <p className="field-hint" style={{ marginTop: 0 }}>
        Shown as a banner to your whole team (yourself and your editors) on the date(s) you choose — e.g. an office
        closure or holiday notice.
      </p>
      {error && <p className="sq-row-error">{error}</p>}
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          createMutation.mutate();
        }}
      >
        <label>Message</label>
        <textarea rows={2} value={message} onChange={(e) => setMessage(e.target.value)} required />
        <label>Type</label>
        <select value={kind} onChange={(e) => setKind(e.target.value as Announcement['kind'])}>
          <option value="info">Info</option>
          <option value="warning">Warning</option>
          <option value="success">Success</option>
          <option value="danger">Urgent</option>
        </select>
        <div className="gap-row">
          <div style={{ flex: 1 }}>
            <label>Show from</label>
            <input
              type="date"
              value={startDate}
              onChange={(e) => {
                setStartDate(e.target.value);
                if (e.target.value > endDate) setEndDate(e.target.value);
              }}
              required
            />
          </div>
          <div style={{ flex: 1 }}>
            <label>Through</label>
            <input type="date" value={endDate} min={startDate} onChange={(e) => setEndDate(e.target.value)} required />
          </div>
        </div>
        <label>Send to</label>
        <select value={targetUserId} onChange={(e) => setTargetUserId(e.target.value)}>
          <option value="">Everyone on my team</option>
          {teamMembers.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name} ({m.email})
            </option>
          ))}
        </select>
        <button type="submit" disabled={createMutation.isPending}>
          {createMutation.isPending ? 'Creating…' : 'Create Announcement'}
        </button>
      </form>

      <div className="timeslot-list" style={{ marginTop: 16 }}>
        {isLoading && <p className="subtitle">Loading…</p>}
        {!isLoading && announcements.length === 0 && <p className="subtitle">No announcements yet.</p>}
        {announcements.map((a) => (
          <div
            key={a.id}
            className="sq-post-card"
            style={{ alignItems: 'center', opacity: a.end_date < todayIso() ? 0.6 : 1 }}
          >
            <div className="sq-post-body">
              <p className="sq-post-text">{a.message}</p>
              <div className="sq-post-accounts">
                <span className="sq-account-chip">
                  <span className="sq-account-name">{a.kind}</span>
                </span>
                <span className="small-text">
                  {a.start_date === a.end_date ? a.start_date : `${a.start_date} → ${a.end_date}`}
                  {isActive(a) && ' · active now'}
                  {' · '}
                  {a.target_user_id ? `for ${a.target_user_name ?? 'one user'}` : 'for everyone'}
                </span>
              </div>
            </div>
            <div className="sq-post-actions">
              <button
                type="button"
                className="btn-small btn-danger"
                disabled={deleteMutation.isPending}
                onClick={() => {
                  if (confirm('Delete this announcement?')) {
                    deleteMutation.mutate(a.id);
                  }
                }}
              >
                Delete
              </button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
