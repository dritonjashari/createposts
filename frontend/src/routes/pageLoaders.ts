// Dynamic imports for every page, shared by App.tsx (React.lazy) and the
// background warm-up, so both load the exact same chunks.
export const pageLoaders = {
  login: () => import('./LoginPage'),
  register: () => import('./RegisterPage'),
  dashboard: () => import('./DashboardPage'),
  editorDashboard: () => import('./EditorDashboardPage'),
  sites: () => import('./SitesPage'),
  scheduledPosts: () => import('./ScheduledPostsPage'),
  categories: () => import('./CategoriesPage'),
  mapping: () => import('./MappingPage'),
  publish: () => import('./PublishPage'),
  publishQueue: () => import('./PublishQueuePage'),
  jobsStatus: () => import('./JobsStatusPage'),
  schedule: () => import('./SchedulePage'),
  system: () => import('./SystemPage'),
  users: () => import('./UsersPage'),
  profile: () => import('./ProfilePage'),
  tasks: () => import('./TasksPage'),
  taskDetail: () => import('./TaskDetailPage'),
};

export type PageName = keyof typeof pageLoaders;

export const editorPages: PageName[] = [
  'editorDashboard', 'publish', 'schedule', 'publishQueue', 'scheduledPosts', 'jobsStatus', 'tasks', 'taskDetail', 'profile',
];
export const adminPages: PageName[] = [
  'dashboard', 'publish', 'schedule', 'publishQueue', 'scheduledPosts', 'jobsStatus', 'sites', 'mapping', 'categories',
  'tasks', 'taskDetail', 'users', 'system', 'profile',
];
