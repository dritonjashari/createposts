import { apiRequest } from './client';
import type { Task, TeamMember } from './types';

export function getTasks() {
  return apiRequest<{ tasks: Task[]; assignable_users: TeamMember[]; is_admin: boolean }>('tasks');
}

export function getTask(id: number) {
  return apiRequest<{ task: Task; is_admin: boolean }>(`tasks/${id}`);
}

export interface CreateTaskInput {
  assigned_to: number;
  title: string;
  description: string;
  due_date: string;
  links: string[];
}

export function createTask(input: CreateTaskInput) {
  return apiRequest<{ message: string }>('tasks', { method: 'POST', body: input });
}

export function deleteTask(id: number) {
  return apiRequest<{ message: string }>(`tasks/${id}`, { method: 'DELETE' });
}

export function setTaskStatus(id: number, status: 'open' | 'done') {
  return apiRequest<{ message: string }>(`tasks/${id}/status`, { method: 'POST', body: { status } });
}

export function addTaskNote(id: number, note: string) {
  return apiRequest<{ message: string; notes: Task['notes'] }>(`tasks/${id}/notes`, { method: 'POST', body: { note } });
}
