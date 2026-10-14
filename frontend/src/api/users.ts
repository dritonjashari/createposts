import { apiRequest } from './client';
import type { AppUser, Workspace } from './types';

export function getUsers() {
  return apiRequest<{ users: AppUser[]; workspaces: Workspace[]; self_id: number }>('users');
}

export interface CreateUserInput {
  name: string;
  email: string;
  password: string;
  role: 'admin' | 'editor';
}

export function createUser(input: CreateUserInput) {
  return apiRequest<{ message: string }>('users', { method: 'POST', body: input });
}

export function approveUser(id: number) {
  return apiRequest<{ message: string }>(`users/${id}/approve`, { method: 'POST' });
}

export function setUserRole(id: number, role: 'admin' | 'editor') {
  return apiRequest<{ message: string }>(`users/${id}/role`, { method: 'POST', body: { role } });
}

export function deleteUser(id: number) {
  return apiRequest<{ message: string }>(`users/${id}`, { method: 'DELETE' });
}

/** `null` lifts the restriction (all workspaces); an array restricts to exactly those (empty = none). */
export function setUserWorkspaces(id: number, workspaces: string[] | null) {
  return apiRequest<{ message: string }>(`users/${id}/workspaces`, { method: 'POST', body: { workspaces } });
}
