import { apiRequest } from './client';
import type { FbPage } from './types';

export interface ScheduledAccount {
  id: string;
  name: string;
  scheduled_at: string;
}

export type QueueState = 'scheduled' | 'published';

export interface ScheduledPost {
  id: string;
  text: string;
  comments: string[];
  accounts: ScheduledAccount[];
  scheduled_at: string;
  has_media: boolean;
  media_thumbnail: string | null;
  media_type: string;
  type: string;
  state: string;
  /** Link to the live post on Facebook (published posts). */
  post_link: string;
}

export interface ScheduledPostsResponse {
  posts: ScheduledPost[];
  total: number;
  page: number;
  /** null when Publer is paged straight through (published history). */
  total_pages: number | null;
  has_more: boolean;
  pages: FbPage[];
  state: QueueState;
  /** Set when only part of the queue could be read from Publer. */
  warning?: string | null;
}

export interface ScheduledPostsFilters {
  state?: QueueState;
  page?: number;
  accountId?: string;
  search?: string;
  dateFrom?: string;
  dateTo?: string;
}

export function getScheduledPosts(filters: ScheduledPostsFilters = {}) {
  const { state = 'scheduled', page = 1, accountId = '', search = '', dateFrom = '', dateTo = '' } = filters;
  const params = new URLSearchParams({ page: String(page) });
  if (state !== 'scheduled') params.set('state', state);
  if (accountId) params.set('account_id', accountId);
  if (search) params.set('q', search);
  if (dateFrom) params.set('date_from', dateFrom);
  if (dateTo) params.set('date_to', dateTo);
  return apiRequest<ScheduledPostsResponse>(`scheduled-posts?${params}`);
}

export function deleteScheduledPost(id: string, scheduledAt: string) {
  return apiRequest<{ message: string }>(`scheduled-posts/${encodeURIComponent(id)}`, {
    method: 'DELETE',
    body: { scheduled_at: scheduledAt },
  });
}

export function reschedulePost(id: string, scheduledAt: string) {
  return apiRequest<{ message: string }>(`scheduled-posts/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: { scheduled_at: scheduledAt },
  });
}

export function editScheduledPost(id: string, text: string) {
  return apiRequest<{ message: string }>(`scheduled-posts/${encodeURIComponent(id)}/edit`, {
    method: 'POST',
    body: { text },
  });
}

export interface RepostInput {
  account_ids: string[];
  post_mode: 'now' | 'schedule' | 'timeslot';
  schedule_time?: string;
  page_gap?: number;
  page_gap_unit?: 'minutes' | 'hours';
  add_pixels: boolean;
  include_comments: boolean;
  paraphrase: boolean;
  paraphrase_comments: boolean;
  paraphrase_tone?: string;
  auto_delete: boolean;
  auto_delete_hours?: number;
}

/** Repost an existing post to other pages of the workspace; returns a schedule job to follow. */
export function repostPost(id: string, input: RepostInput) {
  return apiRequest<{ job_id: number }>(`scheduled-posts/${encodeURIComponent(id)}/repost`, {
    method: 'POST',
    body: input,
    timeoutMs: 330_000,
  });
}
