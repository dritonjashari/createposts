import { apiRequest } from './client';
import type { AppNotification } from './types';

export function getNotifications() {
  return apiRequest<{ notifications: AppNotification[]; unread_count: number }>('notifications');
}

export function markNotificationRead(id: number) {
  return apiRequest<{ unread_count: number }>(`notifications/${id}/read`, { method: 'POST' });
}

export function markAllNotificationsRead() {
  return apiRequest<{ unread_count: number }>('notifications/read-all', { method: 'POST' });
}
