import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getMapping, saveMapping } from '../api/mapping';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

export default function MappingPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['mapping'], queryFn: getMapping });

  const [mapping, setMapping] = useState<Record<string, number>>({});
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (data) {
      setMapping(data.mappings);
    }
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: () => saveMapping(mapping),
    onSuccess: (res) => {
      setMessage(res.message);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['mapping'] });
      queryClient.invalidateQueries({ queryKey: ['publish-context'] });
      queryClient.invalidateQueries({ queryKey: ['publer-context'] });
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setMessage(null);
    },
  });

  return (
    <div className="container">
      <h1>Map Websites</h1>
      <p className="subtitle">
        {data ? `Workspace: ${data.active_workspace_name || 'None selected'}` : 'Match each Facebook page to a WordPress site.'}
      </p>
      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      <div className="card">
        {isLoading && <p className="subtitle">Loading…</p>}
        {!isLoading && data?.facebook_pages.length === 0 && (
          <p className="subtitle">No Facebook pages found in this workspace.</p>
        )}
        {!isLoading &&
          data?.facebook_pages.map((page) => (
            <div className="map-row" key={page.id}>
              <div className="map-row-info">
                <strong>{page.name}</strong>
                <span className={`status-pill ${page.connected ? 'status-active' : 'status-down'}`}>
                  {page.connected ? 'Connected' : 'Disconnected'}
                </span>
              </div>
              <select
                value={mapping[page.id] ?? 0}
                onChange={(e) => setMapping((prev) => ({ ...prev, [page.id]: Number(e.target.value) }))}
              >
                <option value={0}>— No website —</option>
                {data.sites.map((site) => (
                  <option key={site.id} value={site.id}>
                    {site.site_name}
                  </option>
                ))}
              </select>
            </div>
          ))}
        {!isLoading && data && data.facebook_pages.length > 0 && (
          <button type="button" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>
            {saveMutation.isPending ? 'Saving…' : 'Save Mappings'}
          </button>
        )}
      </div>
    </div>
  );
}
