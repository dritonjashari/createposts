export interface Site {
  id: number;
  user_id: number;
  site_name: string;
  site_url: string;
  username: string;
  application_password: string;
  ga_property_id: string | null;
  created_at: string;
}

export interface Category {
  id: number;
  user_id: number;
  name: string;
  site_id: number | null;
  is_default: 0 | 1;
  created_at: string;
  site_name: string | null;
}

export interface Workspace {
  id: string;
  name: string;
}

export interface FbPage {
  id: string;
  name: string;
  social_id: string;
  connected: boolean;
}

export interface Timeslot {
  id: number;
  time: string;
  days: number[];
  ws: string;
}

export interface PublishedPost {
  id: number;
  user_id: number;
  site_id: number;
  title: string;
  remote_post_id: number;
  status: string;
  posted_by: number | null;
  created_at: string;
  site_name: string | null;
  site_url: string | null;
  posted_by_name?: string;
}

export interface TeamMember {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'editor';
  created_at: string;
}

export interface AppNotification {
  id: number;
  user_id: number;
  message: string;
  task_id: number | null;
  is_read: 0 | 1;
  created_at: string;
}

export interface ScheduledPost {
  id: string;
  text: string;
  account: string;
  scheduled_at: string;
  state: string;
}

export interface AppUser {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'editor';
  status: 'active' | 'pending';
  created_at: string;
  /** false = never restricted (all workspaces); true = only workspace_ids (may be none). */
  workspaces_restricted: boolean;
  workspace_ids: string[];
}

export interface TaskNote {
  id: number;
  task_id: number;
  user_id: number;
  note: string;
  created_at: string;
  author_name: string;
}

export interface TaskLink {
  id: number;
  task_id: number;
  url: string;
  created_at: string;
}

/**
 * The list endpoint returns note_count/link_count (no arrays); the detail
 * endpoint returns the full notes/links arrays (no counts) plus both names.
 */
export interface Task {
  id: number;
  admin_id: number;
  assigned_to: number;
  title: string;
  description: string | null;
  status: 'open' | 'done';
  due_date: string | null;
  created_at: string;
  completed_at: string | null;
  assignee_name?: string;
  admin_name?: string;
  note_count?: number;
  link_count?: number;
  notes?: TaskNote[];
  links?: TaskLink[];
}

export type PublishJobState = 'queued' | 'processing' | 'done' | 'error';

export interface PublishJobResult {
  success: boolean;
  title?: string;
  post_id?: number;
  draft_post_id?: number;
  /** true while Phase 1 draft exists but Phase 2 content processing is still running */
  pending?: boolean;
  message?: string;
  name?: string;
  /** true once handed off to a newer retry job — no longer counted as a live failure */
  retried?: boolean;
  retried_as_job_id?: number;
}

export interface PublishJobSummary {
  id: number;
  title: string;
  total: number;
  done: number;
  state: PublishJobState;
  results: Record<string, PublishJobResult>;
  fail_count: number;
  created_at: string;
  finished_at: string | null;
  user_id: number;
  posted_by_name: string | null;
  featured_image_url: string | null;
  editable_site_id: number | null;
  editable_post_id: number | null;
  /** Every site the article went out to successfully — each can be edited. */
  edit_targets: { site_id: number; post_id: number; name: string }[];
  /** The job had a featured image (thumbnail may still need a lazy lookup). */
  has_image: boolean;
}

export interface WordPressPostDetail {
  title: string;
  content: string;
  status: string;
  link: string;
  featured_image_url: string | null;
}

export interface PublishJobStatus {
  state: PublishJobState;
  total: number;
  done: number;
  results: Record<string, PublishJobResult> | null;
}

export interface ScheduleJobResult {
  success: boolean;
  name?: string;
  message?: string;
}

export interface ScheduleJobStatus {
  state: PublishJobState;
  total: number;
  done: number;
  results: Record<string, ScheduleJobResult> | null;
}

export type JobType = 'publish' | 'schedule';

export interface JobResult {
  success: boolean;
  message?: string;
  name?: string;
  /** publish jobs only: draft created but content processing still running */
  pending?: boolean;
  /** true once handed off to a newer retry job — no longer counted as a live failure */
  retried?: boolean;
  retried_as_job_id?: number;
}

/** One row of the unified Jobs Status page — a publish_jobs or schedule_jobs row. */
export interface JobSummary {
  job_type: JobType;
  id: number;
  label: string;
  total: number;
  done: number;
  state: PublishJobState;
  results: Record<string, JobResult>;
  fail_count: number;
  created_at: string;
  finished_at: string | null;
  user_id: number;
  posted_by_name: string | null;
}
