import { apiRequest } from './client';
import type { JobSummary, JobType } from './types';

export interface JobsPage {
  jobs: JobSummary[];
  page: number;
  total_pages: number;
  total: number;
  is_admin: boolean;
}

export function getJobs(page = 1) {
  return apiRequest<JobsPage>(`jobs?page=${page}`);
}

export function retryJob(jobType: JobType, id: number) {
  return apiRequest<{ job_id: number }>(`jobs/${jobType}/${id}/retry`, { method: 'POST' });
}
