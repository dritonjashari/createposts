import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { approveUser, createUser, deleteUser, getUsers, setUserRole, setUserWorkspaces } from '../api/users';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import type { AppUser } from '../api/types';

export default function UsersPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['users'], queryFn: getUsers });

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [role, setRole] = useState<'admin' | 'editor'>('editor');
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['users'] });
    // Dashboard team lists / pending counts depend on users too.
    queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  }

  function showSuccess(res: { message: string }) {
    setMessage(res.message);
    setError(null);
    invalidate();
  }

  function showError(err: unknown) {
    setError(err instanceof ApiError ? err.message : 'Something went wrong.');
    setMessage(null);
    // The UI may now be out of step with the server (e.g. a rejected role
    // change) — re-read the real state.
    invalidate();
  }

  const createMutation = useMutation({
    mutationFn: () => createUser({ name, email, password, role }),
    onSuccess: (res) => {
      setMessage(res.message);
      setError(null);
      setName('');
      setEmail('');
      setPassword('');
      setRole('editor');
      invalidate();
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setMessage(null);
    },
  });

  const roleMutation = useMutation({
    mutationFn: ({ id, role: r }: { id: number; role: 'admin' | 'editor' }) => setUserRole(id, r),
    onSuccess: showSuccess,
    onError: showError,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteUser(id),
    onSuccess: showSuccess,
    onError: showError,
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => approveUser(id),
    onSuccess: showSuccess,
    onError: showError,
  });

  const workspacesMutation = useMutation({
    mutationFn: ({ id, workspaces }: { id: number; workspaces: string[] | null }) => setUserWorkspaces(id, workspaces),
    onSuccess: showSuccess,
    onError: showError,
  });

  function toggleAllWorkspaces(u: AppUser, allIds: string[]) {
    // Switching "All" off starts from every workspace ticked, so the admin
    // narrows it down rather than instantly locking the user out.
    workspacesMutation.mutate({ id: u.id, workspaces: u.workspaces_restricted ? null : allIds });
  }

  // Only workspaces that still exist in Publer: ids of deleted ones (or from a
  // previous API key) would otherwise linger in the saved list for good.
  function grantedIds(u: AppUser): string[] {
    return u.workspace_ids.filter((id) => data?.workspaces.some((w) => w.id === id));
  }

  function toggleWorkspace(u: AppUser, wsId: string) {
    const current = grantedIds(u);
    const next = current.includes(wsId) ? current.filter((id) => id !== wsId) : [...current, wsId];
    if (next.length === 0 && !confirm(`Remove all workspace access for ${u.name}? They will not be able to schedule posts.`)) {
      return;
    }
    workspacesMutation.mutate({ id: u.id, workspaces: next });
  }

  const pendingUsers = data?.users.filter((u) => u.status === 'pending') ?? [];

  return (
    <div className="container container-wide">
      <h1>Users</h1>
      <p className="subtitle">Manage team members and their workspace access.</p>
      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      {pendingUsers.length > 0 && (
        <div className="card">
          <h2>Pending Approval <small>({pendingUsers.length})</small></h2>
          <p className="field-hint" style={{ marginTop: 0 }}>
            These accounts registered themselves and cannot log in until approved.
          </p>
          <div className="table-wrap">
            <table className="user-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Requested</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {pendingUsers.map((u) => (
                  <tr key={u.id}>
                    <td>{u.name}</td>
                    <td>{u.email}</td>
                    <td>{new Date(u.created_at).toLocaleString()}</td>
                    <td>
                      <div style={{ display: 'flex', gap: 8 }}>
                        <button
                          type="button"
                          className="btn-small"
                          disabled={approveMutation.isPending}
                          onClick={() => approveMutation.mutate(u.id)}
                        >
                          Approve
                        </button>
                        <button
                          type="button"
                          className="btn-small btn-danger"
                          disabled={deleteMutation.isPending}
                          onClick={() => {
                            if (confirm(`Reject and delete the registration request from ${u.name}?`)) {
                              deleteMutation.mutate(u.id);
                            }
                          }}
                        >
                          Reject
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="card">
        <h2>Add User</h2>
        <form
          onSubmit={(e: FormEvent) => {
            e.preventDefault();
            createMutation.mutate();
          }}
        >
          <label>Name</label>
          <input value={name} onChange={(e) => setName(e.target.value)} required />
          <label>Email</label>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          <label>Password</label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={6} />
          <label>Role</label>
          <select value={role} onChange={(e) => setRole(e.target.value as 'admin' | 'editor')}>
            <option value="editor">Editor</option>
            <option value="admin">Admin</option>
          </select>
          <button type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? 'Adding…' : 'Add User'}
          </button>
        </form>
      </div>

      <div className="card">
        <h2>All Users</h2>
        {isLoading && <p className="subtitle">Loading…</p>}
        {!isLoading && data && (
          <div className="table-wrap">
            <table className="user-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Workspace access</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {data.users.map((u) => {
                  const isSelf = u.id === data.self_id;
                  const granted = grantedIds(u);
                  return (
                    <tr key={u.id}>
                      <td>{u.name}</td>
                      <td>{u.email}</td>
                      <td>
                        <span className={`role-pill role-${u.role}`}>{u.role}</span>
                        {u.status === 'pending' && (
                          <span className="status-pill status-down" style={{ marginLeft: 6 }}>Pending</span>
                        )}
                      </td>
                      <td>
                        {u.role === 'admin' ? (
                          <>
                            <span className="small-text">All workspaces</span>
                            <div className="field-hint">Admins always have every workspace.</div>
                          </>
                        ) : data.workspaces.length === 0 ? (
                          <span className="small-text">—</span>
                        ) : (
                          <>
                            <div className="ws-checks">
                              <label className="ws-check">
                                <input
                                  type="checkbox"
                                  checked={!u.workspaces_restricted}
                                  disabled={workspacesMutation.isPending}
                                  onChange={() => toggleAllWorkspaces(u, data.workspaces.map((w) => w.id))}
                                />
                                <strong>All workspaces</strong>
                              </label>
                              {data.workspaces.map((ws) => (
                                <label className="ws-check" key={ws.id}>
                                  <input
                                    type="checkbox"
                                    checked={!u.workspaces_restricted || granted.includes(ws.id)}
                                    disabled={!u.workspaces_restricted || workspacesMutation.isPending}
                                    onChange={() => toggleWorkspace(u, ws.id)}
                                  />
                                  {ws.name}
                                </label>
                              ))}
                            </div>
                            {u.workspaces_restricted && granted.length === 0 && (
                              <div className="field-hint">No workspace access</div>
                            )}
                          </>
                        )}
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                          <select
                            className="inline-form"
                            disabled={isSelf || roleMutation.isPending}
                            value={u.role}
                            onChange={(e) => {
                              const next = e.target.value as 'admin' | 'editor';
                              const msg = next === 'admin'
                                ? `Make ${u.name} an admin? They will get full access to Sites, System and Users.`
                                : `Make ${u.name} an editor? Any editors they created will be moved to you.`;
                              if (confirm(msg)) {
                                roleMutation.mutate({ id: u.id, role: next });
                              }
                            }}
                          >
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                          </select>
                          <button
                            type="button"
                            className="btn-small btn-danger"
                            disabled={isSelf || deleteMutation.isPending}
                            onClick={() => {
                              if (confirm(u.role === 'admin'
                                ? `Delete admin ${u.name}? Their websites and settings are deleted too; their editors will be moved to you.`
                                : `Delete user ${u.name}?`)) {
                                deleteMutation.mutate(u.id);
                              }
                            }}
                          >
                            Delete
                          </button>
                        </div>
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
  );
}
