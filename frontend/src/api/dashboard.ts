import { apiRequest } from './client';
import type { PublishedPost, ScheduledPost, Task } from './types';
import type { User } from '../context/AuthContext';

export type DashboardDays = 7 | 30;

export interface PendingUserSummary {
  id: number;
  name: string;
  email: string;
  created_at: string;
}

export interface JobAlert {
  id: number;
  title?: string;
  text?: string;
  total: number;
  done: number;
  fail_count: number;
  finished_at: string;
}

/** Period numbers. "web" = website posts, "social" = Facebook pages posted to. */
export interface DashboardTotals {
  web: number;
  social: number;
  failed: number;
  web_prev: number;
  social_prev: number;
  web_today: number;
  social_today: number;
  /** % of attempts that succeeded; null when nothing was attempted. */
  success_rate: number | null;
  success_rate_prev: number | null;
}

export interface DashboardDay {
  date: string; // Y-m-d in the app timezone
  web: number;
  social: number;
  failed: number;
}

export interface TeamRow {
  id: number;
  name: string;
  role: 'admin' | 'editor';
  web: number;
  social: number;
  failed: number;
  success_rate: number | null;
  last_active: string | null;
}

export interface SiteRow {
  id: number;
  name: string;
  url: string;
  posts: number;
  last_post: string | null;
}

interface DashboardBase {
  user: User;
  days: DashboardDays;
  timezone: string;
  totals: DashboardTotals;
  series: DashboardDay[];
  /** Posts waiting in Publer (null when Publer couldn't be reached). */
  queue_count: number | null;
  open_tasks: Task[];
  open_task_count: number;
  publish_alerts: JobAlert[];
  schedule_alerts: JobAlert[];
}

export interface AdminDashboard extends DashboardBase {
  role: 'admin';
  team: TeamRow[];
  sites: SiteRow[];
  finished_tasks: Task[];
  pending_users: PendingUserSummary[];
}

export interface EditorDashboard extends DashboardBase {
  role: 'editor';
  scheduled_posts: ScheduledPost[];
}

export function getDashboard(days: DashboardDays = 7) {
  return apiRequest<AdminDashboard | EditorDashboard>(`dashboard?days=${days}`);
}

export interface DashboardPostsPage {
  posts: PublishedPost[];
  page: number;
  total_pages: number;
  total: number;
}

/** Recent website posts (the team's for admins, your own for editors). */
export function getDashboardPosts(page = 1) {
  return apiRequest<DashboardPostsPage>(`dashboard/posts?page=${page}`);
}

export function saveGaId(gaId: string) {
  return apiRequest<{ message: string }>('dashboard/ga', { method: 'POST', body: { ga_id: gaId } });
}
