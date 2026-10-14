import { apiRequest } from './client';
import type { FbPage, ScheduleJobStatus, Site, Timeslot, Workspace } from './types';

export interface PublerContext {
  editors_can_schedule: boolean;
  workspaces: Workspace[];
  active_workspace_id: string;
  active_workspace_name: string;
  facebook_pages: FbPage[];
  sites: Site[];
  mappings: Record<string, number>;
  is_admin: boolean;
  timezone: string;
  default_gap_value: number;
  default_gap_unit: 'minutes' | 'hours';
  default_use_mapped: boolean;
  mapped_link_target: 'comment1' | 'comment2';
  default_paraphrase: boolean;
  default_paraphrase_comment: boolean;
  default_paraphrase_comment2: boolean;
  default_comment_delay_minutes: number;
  default_comment2_delay_minutes: number;
  image_format: '' | '1:1' | '4:5' | '9:16' | '16:9';
  /** System-page default for the Publer delete callback, in hours (0 = off). */
  default_auto_delete_hours: number;
  /** System default for the "add random white pixels" option. */
  default_add_pixels: boolean;
  timeslots: Timeslot[];
  next_timeslot_preview: number | null;
  next_timeslot_preview_formatted: string | null;
  primary_page_id: string;
}

export function getPublerContext() {
  return apiRequest<PublerContext>('publer/context');
}

export interface ScheduleResult {
  job_id?: number;
  message?: string;
  page_count?: number;
  media_kind?: string;
  base_timestamp?: number;
  gap_seconds?: number;
  paraphrase_failures?: number;
  mapping_notes?: string[];
}

export function schedulePost(formData: FormData) {
  return apiRequest<ScheduleResult>('publer/schedule', { method: 'POST', formData });
}

export function getScheduleJob(id: number) {
  return apiRequest<ScheduleJobStatus>(`publer/jobs/${id}`);
}
