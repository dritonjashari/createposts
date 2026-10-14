import { apiRequest } from './client';
import type { User } from '../context/AuthContext';

export function getProfile() {
  return apiRequest<{ user: User }>('profile');
}

export function updateProfile(name: string, email: string) {
  return apiRequest<{ message: string; user: User }>('profile', { method: 'POST', body: { name, email } });
}

export function changePassword(currentPassword: string, newPassword: string, confirmPassword: string) {
  return apiRequest<{ message: string }>('profile/password', {
    method: 'POST',
    body: { current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword },
  });
}
