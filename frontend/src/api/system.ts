import { apiRequest } from './client';
import type { FbPage, Timeslot, Workspace } from './types';

export interface SystemContext {
  workspaces: Workspace[];
  active_workspace_id: string;
  timezone: string;
  gap_value: number;
  gap_unit: 'minutes' | 'hours';
  default_use_mapped: boolean;
  mapped_link_target: 'comment1' | 'comment2';
  default_paraphrase_social: boolean;
  default_paraphrase_comment: boolean;
  default_paraphrase_comment2: boolean;
  comment_delay_minutes: number;
  comment2_delay_minutes: number;
  default_paraphrase_web: boolean;
  image_format: '' | '1:1' | '4:5' | '9:16' | '16:9';
  auto_clean_text: boolean;
  editors_can_schedule: boolean;
  auto_delete_enabled: boolean;
  auto_delete_hours: number;
  /** Stamp each page's copy of a photo with random white pixels by default. */
  default_add_pixels: boolean;
  timeslots: Timeslot[];
  pages_by_workspace: Record<string, FbPage[]>;
  first_page_by_workspace: Record<string, string>;
  openai: {
    api_key: string;
    model: string;
    price_in: string;
    price_out: string;
    effective_price_in: number;
    effective_price_out: number;
    spend: number;
  };
  publer_api_key: string;
  smtp: {
    host: string;
    port: string;
    user: string;
    pass: string;
    /** True when a password is saved (the password itself is never sent). */
    pass_set?: boolean;
    from: string;
    from_name: string;
    notify_email: string;
  };
}

export function getSystem() {
  return apiRequest<SystemContext>('system');
}

export interface SaveSchedulingInput {
  gap_value: number;
  gap_unit: 'minutes' | 'hours';
  image_format: string;
  timezone: string;
  first_page_id: Record<string, string>;
  default_use_mapped: boolean;
  mapped_link_target: 'comment1' | 'comment2';
  default_paraphrase_social: boolean;
  default_paraphrase_comment: boolean;
  default_paraphrase_comment2: boolean;
  comment_delay_minutes: number;
  comment2_delay_minutes: number;
  default_paraphrase_web: boolean;
  auto_clean_text: boolean;
  editors_can_schedule: boolean;
  auto_delete_enabled: boolean;
  auto_delete_hours: number;
  default_add_pixels: boolean;
}

export function saveScheduling(input: SaveSchedulingInput) {
  return apiRequest<{ message: string }>('system/scheduling', { method: 'POST', body: input });
}

export function saveActiveWorkspace(workspaceId: string) {
  return apiRequest<{ message: string }>('system/workspace', { method: 'POST', body: { workspace_id: workspaceId } });
}

export function addTimeslot(time: string, workspaceId: string, days: number[]) {
  return apiRequest<{ message: string; timeslots: Timeslot[] }>('system/timeslots', {
    method: 'POST',
    body: { time, workspace_id: workspaceId, days },
  });
}

export function removeTimeslot(id: number) {
  return apiRequest<{ message: string }>(`system/timeslots/${id}`, { method: 'DELETE' });
}

export function savePublerKey(publerApiKey: string) {
  return apiRequest<{ message: string; status: 'success' | 'error' | 'warning' }>('system/publer-key', {
    method: 'POST',
    body: { publer_api_key: publerApiKey },
  });
}

export interface SaveOpenAiInput {
  api_key: string;
  model: string;
  price_in?: number;
  price_out?: number;
}

export function saveOpenAi(input: SaveOpenAiInput) {
  return apiRequest<{ message: string; status: 'success' | 'error' | 'warning' }>('system/openai', {
    method: 'POST',
    body: input,
  });
}

export interface SaveSmtpInput {
  host: string;
  port: string;
  user: string;
  pass: string;
  from: string;
  from_name: string;
  notify_email: string;
}

export function saveSmtp(input: SaveSmtpInput) {
  return apiRequest<{ message: string }>('system/smtp', { method: 'POST', body: input });
}
