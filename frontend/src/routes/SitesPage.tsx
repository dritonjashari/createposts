import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createSite, deleteSite, getSites } from '../api/sites';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

export default function SitesPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['sites'], queryFn: getSites });

  const [siteName, setSiteName] = useState('');
  const [siteUrl, setSiteUrl] = useState('');
  const [username, setUsername] = useState('');
  const [appPassword, setAppPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const createMutation = useMutation({
    mutationFn: () => createSite({ site_name: siteName, site_url: siteUrl, username, application_password: appPassword }),
    onSuccess: (res) => {
      queryClient.setQueryData(['sites'], { sites: res.sites });
      setMessage(res.message);
      setError(null);
      setSiteName('');
      setSiteUrl('');
      setUsername('');
      setAppPassword('');
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setMessage(null);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteSite(id),
    onSuccess: (_res, id) => {
      queryClient.setQueryData(['sites'], (prev: { sites: { id: number }[] } | undefined) =>
        prev ? { sites: prev.sites.filter((s) => s.id !== id) } : prev,
      );
    },
  });

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    createMutation.mutate();
  }

  return (
    <div className="container">
      <h1>Websites</h1>
      <p className="subtitle">Connect the WordPress sites you publish to.</p>
      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      <div className="sites-layout">
        <div className="card sites-form">
          <h2>Add New Site</h2>
          <form onSubmit={handleSubmit}>
            <label>Site name</label>
            <input value={siteName} onChange={(e) => setSiteName(e.target.value)} required />
            <label>Site URL</label>
            <input
              type="text"
              placeholder="https://example.com or sub.example.com"
              value={siteUrl}
              onChange={(e) => setSiteUrl(e.target.value)}
              required
            />
            <label>Username</label>
            <input value={username} onChange={(e) => setUsername(e.target.value)} required />
            <label>Application password</label>
            <input type="password" value={appPassword} onChange={(e) => setAppPassword(e.target.value)} required />
            <button type="submit" disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Connecting…' : 'Add Site'}
            </button>
          </form>
        </div>

        <div className="card">
          <h2>Saved Sites</h2>
          {isLoading && <p className="subtitle">Loading…</p>}
          {!isLoading && data?.sites.length === 0 && <p className="subtitle">No sites connected yet.</p>}
          <ul className="site-items">
            {data?.sites.map((site) => (
              <li className="site-item" key={site.id}>
                <div>
                  <strong>{site.site_name}</strong>
                  <div className="small-text">{site.site_url}</div>
                </div>
                <button
                  type="button"
                  className="btn-small btn-danger"
                  disabled={deleteMutation.isPending}
                  onClick={() => {
                    if (confirm(`Remove ${site.site_name}?`)) {
                      deleteMutation.mutate(site.id);
                    }
                  }}
                >
                  Delete
                </button>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
