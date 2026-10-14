import { apiRequest } from './client';
import type { Category, Site } from './types';

export function getCategories() {
  return apiRequest<{ categories: Category[]; sites: Site[] }>('categories');
}

export function createCategory(name: string, siteId: number | null) {
  return apiRequest<{ message: string; categories: Category[] }>('categories', {
    method: 'POST',
    body: { name, site_id: siteId ?? 0 },
  });
}

export function deleteCategory(id: number) {
  return apiRequest<{ message: string }>(`categories/${id}`, { method: 'DELETE' });
}

export function setDefaultCategory(id: number) {
  return apiRequest<{ message: string; categories: Category[] }>(`categories/${id}/default`, { method: 'POST' });
}
