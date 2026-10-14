import { lazy, Suspense, useLayoutEffect, useRef } from 'react';
import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { useAuth } from './context/AuthContext';
import { useSharedMedia } from './context/SharedMediaContext';
import Sidebar from './components/nav/Sidebar';
import AnnouncementBanner from './components/AnnouncementBanner';
import QueryErrorBanner from './components/QueryErrorBanner';
import PageErrorBoundary from './components/PageErrorBoundary';
import Warmup from './components/Warmup';
import { pageLoaders } from './routes/pageLoaders';

// Route-based code splitting: each page becomes its own chunk, so an editor
// account that never opens admin-only pages (System/Users/Sites/...) never
// downloads that JS at all.
const LoginPage = lazy(pageLoaders.login);
const RegisterPage = lazy(pageLoaders.register);
const DashboardPage = lazy(pageLoaders.dashboard);
const EditorDashboardPage = lazy(pageLoaders.editorDashboard);
const SitesPage = lazy(pageLoaders.sites);
const ScheduledPostsPage = lazy(pageLoaders.scheduledPosts);
const CategoriesPage = lazy(pageLoaders.categories);
const MappingPage = lazy(pageLoaders.mapping);
const PublishPage = lazy(pageLoaders.publish);
const PublishQueuePage = lazy(pageLoaders.publishQueue);
const JobsStatusPage = lazy(pageLoaders.jobsStatus);
const SchedulePage = lazy(pageLoaders.schedule);
const SystemPage = lazy(pageLoaders.system);
const UsersPage = lazy(pageLoaders.users);
const ProfilePage = lazy(pageLoaders.profile);
const TasksPage = lazy(pageLoaders.tasks);
const TaskDetailPage = lazy(pageLoaders.taskDetail);

function RequireAdmin() {
  const { user } = useAuth();
  if (user?.role !== 'admin') {
    return <Navigate to="/" replace />;
  }
  return <Outlet />;
}

function RequireAuth() {
  const { user, loading } = useAuth();
  const { setSharedFile } = useSharedMedia();
  const location = useLocation();
  const prevUserId = useRef<number | null | undefined>(undefined);

  // The provider tree (including SharedMediaProvider) is never remounted on
  // login/logout — it's the same browser tab, just a token swap — so a file
  // shared between Publish/Schedule by one user would otherwise still be
  // sitting in memory for whoever logs into this tab next. Drop it whenever
  // the logged-in identity actually changes.
  useLayoutEffect(() => {
    const currentId = user?.id ?? null;
    if (prevUserId.current !== undefined && prevUserId.current !== currentId) {
      setSharedFile(null);
    }
    prevUserId.current = currentId;
  }, [user, setSharedFile]);

  // Only on the very first visit (no saved user yet) — and auth/me answers in
  // a few ms. Render nothing rather than flashing a "Loading…" screen.
  if (loading) {
    return null;
  }
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  return (
    <div className="app-shell">
      <Sidebar />
      <Warmup />
      <div className="app-content">
        <AnnouncementBanner />
        <QueryErrorBanner />
        {/* Page-level boundary + Suspense: while a page's chunk loads (or if
            it crashes) only the content area changes — the sidebar stays put
            instead of the whole app blanking on every navigation. */}
        <PageErrorBoundary key={location.pathname}>
          {/* Page code is preloaded by <Warmup />, so this is normally never
              shown; if it is, keep the area blank instead of "Loading…". */}
          <Suspense fallback={null}>
            <Outlet />
          </Suspense>
        </PageErrorBoundary>
      </div>
    </div>
  );
}

function HomeRedirect() {
  const { user } = useAuth();
  return <Navigate to={user?.role === 'admin' ? '/dashboard' : '/editor-dashboard'} replace />;
}

export default function App() {
  return (
    <Suspense fallback={null}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route element={<RequireAuth />}>
          <Route path="/" element={<HomeRedirect />} />
          <Route path="/editor-dashboard" element={<EditorDashboardPage />} />
          <Route path="/publish" element={<PublishPage />} />
          <Route path="/publish-queue" element={<PublishQueuePage />} />
          <Route path="/jobs-status" element={<JobsStatusPage />} />
          <Route path="/schedule" element={<SchedulePage />} />
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/queue" element={<ScheduledPostsPage />} />
          <Route path="/tasks" element={<TasksPage />} />
          <Route path="/tasks/:id" element={<TaskDetailPage />} />
          <Route element={<RequireAdmin />}>
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/sites" element={<SitesPage />} />
            <Route path="/categories" element={<CategoriesPage />} />
            <Route path="/mapping" element={<MappingPage />} />
            <Route path="/system" element={<SystemPage />} />
            <Route path="/users" element={<UsersPage />} />
          </Route>
        </Route>
      </Routes>
    </Suspense>
  );
}
