import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { createCategory, deleteCategory, getCategories, setDefaultCategory } from '../api/categories';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

export default function CategoriesPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['categories'], queryFn: getCategories });

  const [name, setName] = useState('');
  const [siteId, setSiteId] = useState('0');
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['categories'] });
  }

  const createMutation = useMutation({
    mutationFn: () => createCategory(name, siteId === '0' ? null : Number(siteId)),
    onSuccess: (res) => {
      setMessage(res.message);
      setError(null);
      setName('');
      setSiteId('0');
      invalidate();
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setMessage(null);
    },
  });

  const deleteMutation = useMutation({ mutationFn: (id: number) => deleteCategory(id), onSuccess: invalidate });
  const defaultMutation = useMutation({ mutationFn: (id: number) => setDefaultCategory(id), onSuccess: invalidate });

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    createMutation.mutate();
  }

  return (
    <div className="container">
      <h1>Categories</h1>
      <p className="subtitle">Categories are applied when publishing posts to WordPress.</p>
      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      <div className="cat-layout">
        <div className="card">
          <h2>Add Category</h2>
          <form onSubmit={handleSubmit}>
            <label>Name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} required />
            <label>Applies to</label>
            <select value={siteId} onChange={(e) => setSiteId(e.target.value)}>
              <option value="0">All websites</option>
              {data?.sites.map((site) => (
                <option key={site.id} value={site.id}>
                  {site.site_name}
                </option>
              ))}
            </select>
            <button type="submit" disabled={createMutation.isPending}>
              {createMutation.isPending ? 'Adding…' : 'Add Category'}
            </button>
          </form>
        </div>

        <div className="card">
          <h2>Categories</h2>
          {isLoading && <p className="subtitle">Loading…</p>}
          {!isLoading && data?.categories.length === 0 && <p className="subtitle">No categories yet.</p>}
          {!isLoading && data && data.categories.length > 0 && (
            <div className="table-wrap">
              <table className="posts-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Applies to</th>
                    <th>Default</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {data.categories.map((cat) => (
                    <tr key={cat.id}>
                      <td>{cat.name}</td>
                      <td className="muted-cell">{cat.site_name ?? 'All websites'}</td>
                      <td>
                        {cat.is_default ? (
                          <span className="badge badge-publish">Default</span>
                        ) : (
                          <button
                            type="button"
                            className="btn-small"
                            disabled={defaultMutation.isPending}
                            onClick={() => defaultMutation.mutate(cat.id)}
                          >
                            Set default
                          </button>
                        )}
                      </td>
                      <td>
                        <button
                          type="button"
                          className="btn-small btn-danger"
                          disabled={deleteMutation.isPending}
                          onClick={() => {
                            if (confirm(`Delete category "${cat.name}"?`)) {
                              deleteMutation.mutate(cat.id);
                            }
                          }}
                        >
                          Delete
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
