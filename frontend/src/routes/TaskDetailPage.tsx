import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { addTaskNote, deleteTask, getTask, setTaskStatus } from '../api/tasks';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

function fmtDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function fmtDateTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function TaskDetailPage() {
  const { id } = useParams<{ id: string }>();
  const taskId = Number(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data, isLoading, error: loadError } = useQuery({ queryKey: ['task', taskId], queryFn: () => getTask(taskId) });

  const [noteText, setNoteText] = useState('');
  const [error, setError] = useState<string | null>(null);

  function refresh() {
    queryClient.invalidateQueries({ queryKey: ['task', taskId] });
    queryClient.invalidateQueries({ queryKey: ['tasks'] });
  }

  const statusMutation = useMutation({
    mutationFn: (status: 'open' | 'done') => setTaskStatus(taskId, status),
    onSuccess: refresh,
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Could not update the task.'),
  });

  const noteMutation = useMutation({
    mutationFn: () => addTaskNote(taskId, noteText),
    onSuccess: () => {
      setNoteText('');
      setError(null);
      refresh();
    },
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Could not add the note.'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => deleteTask(taskId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tasks'] });
      navigate('/tasks');
    },
    onError: (err) => setError(err instanceof ApiError ? err.message : 'Could not delete the task.'),
  });

  if (isLoading) {
    return (
      <div className="container container-wide">
        <p className="subtitle">Loading…</p>
      </div>
    );
  }

  if (loadError || !data) {
    return (
      <div className="container container-wide">
        <Link className="back-link" to="/tasks">&larr; Back to Tasks</Link>
        <Alert kind="danger">{loadError instanceof ApiError ? loadError.message : 'Task not found.'}</Alert>
      </div>
    );
  }

  const { task, is_admin: isAdmin } = data;
  const isDone = task.status === 'done';
  const dueDate = task.due_date ? new Date(task.due_date + 'T00:00:00') : null;
  const overdue = !!dueDate && !isDone && dueDate.getTime() < new Date(new Date().toDateString()).getTime();
  const otherName = isAdmin ? task.assignee_name : task.admin_name;
  const links = task.links ?? [];
  const notes = task.notes ?? [];

  return (
    <div className="container container-wide">
      <Link className="back-link" to="/tasks">&larr; Back to Tasks</Link>

      {error && <Alert kind="danger">{error}</Alert>}

      <div className="card">
        <div className="task-head">
          <h1 style={{ margin: 0 }}>{task.title}</h1>
          <span className={`task-pill ${isDone ? 'task-done' : 'task-open'}`}>{isDone ? 'Finished' : 'Open'}</span>
          {dueDate && (
            <span className={`task-due${overdue ? ' overdue' : ''}`}>
              Due {fmtDate(task.due_date as string)}{overdue ? ' (overdue)' : ''}
            </span>
          )}
        </div>

        <div className="task-meta">
          {isAdmin ? <>Assigned to <strong>{otherName}</strong></> : <>Assigned by <strong>{otherName}</strong></>}
          {' '}&middot; Created {fmtDate(task.created_at)}
          {isDone && task.completed_at && <> &middot; Finished {fmtDate(task.completed_at)}</>}
        </div>

        {task.description && <div className="task-desc">{task.description}</div>}

        <h2>Links</h2>
        {links.length === 0 ? (
          <p className="no-links">No links added to this task.</p>
        ) : (
          <div className="link-list">
            {links.map((link) => (
              <div className="link-item" key={link.id}>
                🔗 <a href={link.url} target="_blank" rel="noreferrer">{link.url}</a>
              </div>
            ))}
          </div>
        )}

        <div className="task-actions">
          <button
            type="button"
            className={`btn-small${isDone ? ' btn-secondary' : ''}`}
            disabled={statusMutation.isPending}
            onClick={() => statusMutation.mutate(isDone ? 'open' : 'done')}
          >
            {statusMutation.isPending ? 'Saving…' : isDone ? 'Reopen Task' : 'Mark as Finished'}
          </button>
          {isAdmin && (
            <button
              type="button"
              className="btn-small btn-danger"
              disabled={deleteMutation.isPending}
              onClick={() => {
                if (confirm(`Delete "${task.title}"? This can't be undone.`)) {
                  deleteMutation.mutate();
                }
              }}
            >
              {deleteMutation.isPending ? 'Deleting…' : 'Delete Task'}
            </button>
          )}
        </div>
      </div>

      <div className="card">
        <h2>Notes ({notes.length})</h2>

        {notes.length > 0 ? (
          <div className="note-list">
            {notes.map((n) => (
              <div className="note-item" key={n.id}>
                <div className="note-meta">
                  <strong>{n.author_name}</strong> &middot; {fmtDateTime(n.created_at)}
                </div>
                <div>{n.note}</div>
              </div>
            ))}
          </div>
        ) : (
          <p className="no-links">No notes yet.</p>
        )}

        <form
          className="note-form"
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            if (noteText.trim() === '') return;
            noteMutation.mutate();
          }}
        >
          <textarea
            placeholder="Write a note…"
            value={noteText}
            onChange={(e) => setNoteText(e.target.value)}
            required
          />
          <button type="submit" className="btn-small" disabled={noteMutation.isPending}>
            {noteMutation.isPending ? 'Sending…' : 'Send'}
          </button>
        </form>
      </div>
    </div>
  );
}
