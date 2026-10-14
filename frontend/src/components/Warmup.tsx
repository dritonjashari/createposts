import { useEffect } from 'react';
import { useQueryClient, type QueryKey } from '@tanstack/react-query';
import { useAuth } from '../context/AuthContext';
import { adminPages, editorPages, pageLoaders } from '../routes/pageLoaders';
import { getDashboard, getDashboardPosts } from '../api/dashboard';
import { getPublishContext, getPublishJobs } from '../api/publish';
import { getPublerContext } from '../api/publer';
import { getJobs } from '../api/jobs';
import { getTasks } from '../api/tasks';
import { getProfile } from '../api/profile';
import { getSites } from '../api/sites';
import { getMapping } from '../api/mapping';
import { getCategories } from '../api/categories';
import { getUsers } from '../api/users';
import { getSystem } from '../api/system';
import { getAnnouncements } from '../api/announcements';

type Prefetch = [QueryKey, () => Promise<unknown>];

/**
 * Right after login (and on every app start), quietly download every page's
 * code and its first screen of data in the background, so clicking a menu
 * item shows the page immediately instead of "Loading…".
 *
 * The query keys here must match the ones the pages use exactly.
 */
export default function Warmup() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const userId = user?.id;
  const isAdmin = user?.role === 'admin';

  useEffect(() => {
    if (!userId) {
      return;
    }
    let cancelled = false;

    const common: Prefetch[] = [
      [['publer-context'], getPublerContext],
      [['dashboard-posts', 1], () => getDashboardPosts(1)],
      [['publish-context'], getPublishContext],
      [['publish-jobs', 1], () => getPublishJobs(1)],
      [['jobs-status', 1], () => getJobs(1)],
      [['tasks'], getTasks],
      [['profile'], getProfile],
    ];
    const prefetches: Prefetch[] = isAdmin
      ? [
          [['dashboard', 'admin', 7], () => getDashboard(7)],
          ...common,
          [['sites'], getSites],
          [['mapping'], getMapping],
          [['categories'], getCategories],
          [['users'], getUsers],
          [['system'], getSystem],
          [['announcements'], getAnnouncements],
        ]
      : [[['dashboard', 'editor', 7], () => getDashboard(7)], ...common];

    async function run() {
      // Let the current page's own requests go first.
      await new Promise((r) => setTimeout(r, 400));
      // Page code is tiny and static — fetch it all at once.
      for (const name of isAdmin ? adminPages : editorPages) {
        void pageLoaders[name]().catch(() => undefined);
      }
      // Data: two at a time, so the backend isn't flooded. prefetchQuery
      // skips anything still fresh, and never throws.
      const queue = [...prefetches];
      const worker = async () => {
        while (!cancelled && queue.length) {
          const [queryKey, queryFn] = queue.shift()!;
          await queryClient.prefetchQuery({ queryKey, queryFn });
        }
      };
      await Promise.all([worker(), worker()]);
    }
    void run();
    return () => {
      cancelled = true;
    };
  }, [userId, isAdmin, queryClient]);

  return null;
}
