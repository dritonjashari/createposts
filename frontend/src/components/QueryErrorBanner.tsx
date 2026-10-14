import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../api/client';

/**
 * App-wide notice when data on screen failed to load, so a failed request is
 * visible (with a Retry) instead of a page silently showing nothing or
 * "Loading…" forever. Clears itself once every on-screen query is healthy.
 */
export default function QueryErrorBanner() {
  const queryClient = useQueryClient();
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    const cache = queryClient.getQueryCache();
    return cache.subscribe((event) => {
      if (event.type !== 'updated') {
        return;
      }
      if (event.action.type === 'error') {
        // Background warm-up prefetches nobody is looking at yet stay silent;
        // the page shows its own error if it's opened.
        if (event.query.getObserversCount() === 0) {
          return;
        }
        const err = event.action.error;
        // 401 already sends the user to the login page.
        if (err instanceof ApiError && err.status === 401) {
          return;
        }
        setMessage(err instanceof Error ? err.message : 'Something went wrong.');
      } else if (event.action.type === 'success') {
        const stillFailing = cache.findAll({ type: 'active' }).some((q) => q.state.status === 'error');
        if (!stillFailing) {
          setMessage(null);
        }
      }
    });
  }, [queryClient]);

  if (!message) {
    return null;
  }
  return (
    <div className="alert alert-danger" role="alert" style={{ margin: '12px 24px 0' }}>
      Some data could not be loaded: {message}{' '}
      <button
        type="button"
        className="btn-small"
        style={{ marginLeft: 8 }}
        onClick={() => queryClient.refetchQueries({ type: 'active' })}
      >
        Retry
      </button>{' '}
      <button type="button" className="btn-small" onClick={() => setMessage(null)}>
        Dismiss
      </button>
    </div>
  );
}
