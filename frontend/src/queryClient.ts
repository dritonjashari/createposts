import { QueryClient } from '@tanstack/react-query';
import { createSyncStoragePersister } from '@tanstack/query-sync-storage-persister';
import type { PersistQueryClientOptions } from '@tanstack/react-query-persist-client';
import { ApiError } from './api/client';

// Cached data is kept (and saved to localStorage) for a day, so reopening the
// app or a page shows the last known data instantly while a fresh copy loads
// in the background — instead of a "Loading…" screen.
const CACHE_MAX_AGE = 24 * 60 * 60 * 1000;
const CACHE_STORAGE_KEY = 'cp_query_cache';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Don't hammer the backend re-asking a question it already answered
      // with a 4xx (not logged in / no permission / not found) — the default
      // 3 retries with backoff just made failures look like the app froze.
      retry: (failureCount, error) =>
        !(error instanceof ApiError && error.status >= 400 && error.status < 500) && failureCount < 2,
      // Several pages share queries (publer-context, notifications, ...);
      // reuse a fresh-enough result instead of refetching on every mount.
      staleTime: 30_000,
      // Must be at least CACHE_MAX_AGE, or unused queries are dropped (and
      // un-persisted) after the default 5 minutes.
      gcTime: CACHE_MAX_AGE,
      // Changing page/filter keeps showing the current results until the new
      // ones arrive, instead of blanking the list to "Loading…".
      placeholderData: (previous: unknown) => previous,
    },
  },
});

// Never written to localStorage: these responses carry secrets (WordPress
// application passwords, API keys) or are short-lived job progress polls.
const NOT_PERSISTED = new Set(['sites', 'system', 'publish-job', 'schedule-job', 'publish-job-post']);

const persister = createSyncStoragePersister({
  storage: typeof window !== 'undefined' ? window.localStorage : undefined,
  key: CACHE_STORAGE_KEY,
  throttleTime: 1000,
});

export const persistOptions: Omit<PersistQueryClientOptions, 'queryClient'> = {
  persister,
  maxAge: CACHE_MAX_AGE,
  // Bump when response shapes change, so an old saved cache is discarded.
  buster: 'v2',
  dehydrateOptions: {
    shouldDehydrateQuery: (query) =>
      query.state.status === 'success' && !NOT_PERSISTED.has(String(query.queryKey[0])),
  },
};

/** Forget every cached response, in memory and in localStorage (logout / user switch). */
export function clearQueryCache(): void {
  queryClient.clear();
  try {
    localStorage.removeItem(CACHE_STORAGE_KEY);
  } catch {
    // storage unavailable — nothing persisted then
  }
}
