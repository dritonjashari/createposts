import { apiRequest } from './client';
import type { TeamMember } from './types';

export interface Announcement {
  id: number;
  owner_id: number;
  created_by: number;
  target_user_id: number | null;
  target_user_name?: string | null;
  message: string;
  kind: 'info' | 'success' | 'warning' | 'danger';
  start_date: string;
  end_date: string;
  created_at: string;
}

export function getAnnouncements() {
  return apiRequest<{ announcements: Announcement[]; team_members: TeamMember[] }>('announcements');
}

export function getActiveAnnouncements() {
  return apiRequest<{ announcements: Announcement[] }>('announcements/active');
}

export interface CreateAnnouncementInput {
  message: string;
  kind: 'info' | 'success' | 'warning' | 'danger';
  start_date: string;
  end_date: string;
  target_user_id?: number | null;
}

export function createAnnouncement(input: CreateAnnouncementInput) {
  return apiRequest<{ message: string; id: number }>('announcements', { method: 'POST', body: input });
}

export function deleteAnnouncement(id: number) {
  return apiRequest<{ message: string }>(`announcements/${id}`, { method: 'DELETE' });
}
