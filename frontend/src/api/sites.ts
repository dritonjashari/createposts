import { apiRequest } from './client';
import type { Site } from './types';

export function getSites() {
  return apiRequest<{ sites: Site[] }>('sites');
}

export interface CreateSiteInput {
  site_name: string;
  site_url: string;
  username: string;
  application_password: string;
}

export function createSite(input: CreateSiteInput) {
  return apiRequest<{ message: string; sites: Site[] }>('sites', { method: 'POST', body: input });
}

export function deleteSite(id: number) {
  return apiRequest<{ message: string }>(`sites/${id}`, { method: 'DELETE' });
}
