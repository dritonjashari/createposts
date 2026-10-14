import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createTask, deleteTask, getTasks, setTaskStatus } from '../api/tasks';
import type { Task } from '../api/types';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

function fmtDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}

function TaskRow({ task, isAdmin, onChanged }: { task: Task; isAdmin: boolean; onChanged: () => void }) {
  const [rowError, setRowError] = useState<string | null>(null);

  const isDone = task.status === 'done';
  const dueDate = task.due_date ? new Date(task.due_date + 'T00:00:00') : null;
  const overdue = !!dueDate && !isDone && dueDate.getTime() < new Date(new Date().toDateString()).getTime();

  const statusMutation = useMutation({
    mutationFn: () => setTaskStatus(task.id, isDone ? 'open' : 'done'),
    onSuccess: onChanged,
    onError: (err) => setRowError(err instanceof ApiError ? err.message : 'Could not update the task.'),
  });

  const deleteMutation = useMutation({
    mutationFn: () => deleteTask(task.id),
    onSuccess: onChanged,
    onError: (err) => setRowError(err instanceof ApiError ? err.message : 'Could not delete the task.'),
  });

  return (
    <div className="task-card">
      <div className="task-head">
        <Link className="task-title" to={`/tasks/${task.id}`}>{task.title}</Link>
        <span className={`task-pill ${isDone ? 'task-done' : 'task-open'}`}>{isDone ? 'Finished' : 'Open'}</span>
        {dueDate && (
          <span className={`task-due${overdue ? ' overdue' : ''}`}>
            Due {fmtDate(task.due_date as string)}{overdue ? ' (overdue)' : ''}
          </span>
        )}
      </div>

      <div className="task-meta">
        {isAdmin ? (
          <>Assigned to <strong>{task.assignee_name}</strong></>
        ) : (
          <>Assigned by <strong>{task.admin_name}</strong></>
        )}
        {' '}&middot; {fmtDate(task.created_at)}
        {isDone && task.completed_at && <> &middot; Finished {fmtDate(task.completed_at)}</>}
      </div>

      <div className="task-counts">
        🔗 {task.link_count ?? 0} link{(task.link_count ?? 0) === 1 ? '' : 's'}
        {' '}&middot; 💬 {task.note_count ?? 0} note{(task.note_count ?? 0) === 1 ? '' : 's'}
        {' '}&middot; <Link to={`/tasks/${task.id}`}>View details &rarr;</Link>
      </div>

      {rowError && <p className="sq-row-error">{rowError}</p>}

      <div className="task-actions">
        <button
          type="button"
          className={`btn-small${isDone ? ' btn-secondary' : ''}`}
          disabled={statusMutation.isPending}
          onClick={() => { setRowError(null); statusMutation.mutate(); }}
        >
          {statusMutation.isPending ? 'Saving…' : isDone ? 'Reopen Task' : 'Mark as Finished'}
        </button>
        {isAdmin && (
          <button
            type="button"
            className="btn-small btn-danger"
            disabled={deleteMutation.isPending}
            onClick={() => {
              setRowError(null);
              if (confirm(`Delete "${task.title}"? This can't be undone.`)) {
                deleteMutation.mutate();
              }
            }}
          >
            {deleteMutation.isPending ? 'Deleting…' : 'Delete'}
          </button>
        )}
      </div>
    </div>
  );
}

function LinkInputs({ links, setLinks }: { links: string[]; setLinks: (links: string[]) => void }) {
  function update(i: number, value: string) {
    const next = [...links];
    next[i] = value;
    setLinks(next);
  }

  function remove(i: number) {
    if (links.length <= 1) {
      setLinks(['']);
      return;
    }
    setLinks(links.filter((_, idx) => idx !== i));
  }

  return (
    <>
      <div className="link-rows">
        {links.map((link, i) => (
          <div className="link-row" key={i}>
            <input
              type="url"
              placeholder="https://example.com/reference"
              value={link}
              onChange={(e) => update(i, e.target.value)}
            />
            <button type="button" className="link-row-remove" onClick={() => remove(i)}>&times;</button>
          </div>
        ))}
      </div>
      <button type="button" className="add-link-btn" onClick={() => setLinks([...links, ''])}>
        + Add another link
      </button>
    </>
  );
}

export default function TasksPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['tasks'], queryFn: getTasks });

  const [assignedTo, setAssignedTo] = useState('');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [links, setLinks] = useState<string[]>(['']);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  function refresh() {
    queryClient.invalidateQueries({ queryKey: ['tasks'] });
  }

  const createMutation = useMutation({
    mutationFn: () => createTask({ assigned_to: Number(assignedTo), title, description, due_date: dueDate, links }),
    onSuccess: (res) => {
      setMessage(res.message);
      setError(null);
      setTitle('');
      setDescription('');
      setDueDate('');
      setLinks(['']);
      refresh();
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setMessage(null);
    },
  });

  const isAdmin = data?.is_admin ?? false;
  const tasks = data?.tasks ?? [];
  const assignableUsers = data?.assignable_users ?? [];

  return (
    <div className="container container-wide">
      <h1>Tasks</h1>
      <p className="subtitle">
        {isAdmin ? 'Assign tasks to the users you created and track progress.' : 'Tasks your admin assigned to you.'}
      </p>

      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      {isAdmin && (
        <div className="card">
          <h2>Assign a Task</h2>
          {assignableUsers.length === 0 ? (
            <p className="small-text">You haven't created any users yet. Add one on the Users page first.</p>
          ) : (
            <form
              onSubmit={(e: FormEvent) => {
                e.preventDefault();
                createMutation.mutate();
              }}
            >
              <label>Assign to</label>
              <select value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)} required>
                <option value="" disabled>Choose a user…</option>
                {assignableUsers.map((u) => (
                  <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
                ))}
              </select>

              <label>Title</label>
              <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} required />

              <label>Description <small>(optional)</small></label>
              <textarea rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />

              <label>Due date <small>(optional)</small></label>
              <input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />

              <label>Links <small>(optional)</small></label>
              <LinkInputs links={links} setLinks={setLinks} />

              <button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? 'Assigning…' : 'Assign Task'}
              </button>
            </form>
          )}
        </div>
      )}

      <div className="card">
        <h2>{isAdmin ? 'Tasks You Assigned' : 'Your Tasks'}</h2>

        {isLoading ? (
          <p className="subtitle">Loading…</p>
        ) : tasks.length === 0 ? (
          <p className="small-text">{isAdmin ? "You haven't assigned any tasks yet." : 'No tasks assigned to you yet.'}</p>
        ) : (
          tasks.map((task) => (
            <TaskRow key={task.id} task={task} isAdmin={isAdmin} onChanged={refresh} />
          ))
        )}
      </div>
    </div>
  );
}
