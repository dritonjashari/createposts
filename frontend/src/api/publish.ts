import { apiRequest } from './client';
import type { Category, PublishJobStatus, PublishJobSummary, Site, WordPressPostDetail } from './types';

export interface PublishContext {
  sites: Site[];
  sites_filtered_by_workspace: boolean;
  categories: Category[];
  default_category: Category | null;
  is_admin: boolean;
  auto_clean_default: boolean;
  default_paraphrase_web: boolean;
}

export function getPublishContext() {
  return apiRequest<PublishContext>('publish/context');
}

export interface PublishResult {
  job_id?: number;
  success_count?: number;
  success_sites?: string[];
  failures?: string[];
}

export function createPublish(formData: FormData) {
  return apiRequest<PublishResult>('publish', { method: 'POST', formData });
}

export interface ImportedArticle {
  title: string;
  /** Plain text, one paragraph per block, all pages of the article joined. */
  content: string;
  image: { name: string; mime: string; width: number; height: number; base64: string } | null;
  image_url: string;
  pages: number;
  source_url: string;
  warnings: string[];
}

/** Fetch an article from another website to pre-fill the Publish form. */
export function importArticle(url: string) {
  // Multi-page articles are fetched page by page on the server.
  return apiRequest<ImportedArticle>('publish/import', { method: 'POST', body: { url }, timeoutMs: 160_000 });
}

export function getPublishJob(id: number) {
  return apiRequest<PublishJobStatus>(`publish/jobs/${id}`);
}

export function retryPublishJob(id: number) {
  return apiRequest<{ job_id: number }>(`publish/jobs/${id}/retry`, { method: 'POST' });
}

export interface PublishJobsPage {
  jobs: PublishJobSummary[];
  page: number;
  total_pages: number;
  total: number;
  is_admin: boolean;
}

export function getPublishJobs(page = 1) {
  return apiRequest<PublishJobsPage>(`publish/jobs?page=${page}`);
}

/** Featured-image thumbnail of an older published job (looked up once, then cached server-side). */
export function getPublishJobThumbnail(jobId: number) {
  return apiRequest<{ url: string | null }>(`publish/jobs/${jobId}/thumbnail`);
}

export function getPublishJobPost(jobId: number, siteId: number) {
  return apiRequest<WordPressPostDetail>(`publish/jobs/${jobId}/sites/${siteId}/post`);
}

export function updatePublishJobPost(jobId: number, siteId: number, title: string, content: string) {
  return apiRequest<{ message: string }>(`publish/jobs/${jobId}/sites/${siteId}/post`, {
    method: 'POST',
    body: { title, content },
  });
}
