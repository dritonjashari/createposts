import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { changePassword, getProfile, updateProfile } from '../api/profile';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';

export default function ProfilePage() {
  const { data } = useQuery({ queryKey: ['profile'], queryFn: getProfile });

  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [profileMessage, setProfileMessage] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordMessage, setPasswordMessage] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  useEffect(() => {
    if (data) {
      setName(data.user.name);
      setEmail(data.user.email);
    }
  }, [data]);

  const profileMutation = useMutation({
    mutationFn: () => updateProfile(name, email),
    onSuccess: (res) => {
      setProfileMessage(res.message);
      setProfileError(null);
    },
    onError: (err) => {
      setProfileError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setProfileMessage(null);
    },
  });

  const passwordMutation = useMutation({
    mutationFn: () => changePassword(currentPassword, newPassword, confirmPassword),
    onSuccess: (res) => {
      setPasswordMessage(res.message);
      setPasswordError(null);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    },
    onError: (err) => {
      setPasswordError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
      setPasswordMessage(null);
    },
  });

  return (
    <div className="container">
      <h1>Profile</h1>
      <p className="subtitle">Manage your account details.</p>

      <div className="grid">
        <div className="card">
          <h2>Account Details</h2>
          {profileMessage && <Alert kind="success">{profileMessage}</Alert>}
          {profileError && <Alert kind="danger">{profileError}</Alert>}
          <form
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              profileMutation.mutate();
            }}
          >
            <label>Name</label>
            <input value={name} onChange={(e) => setName(e.target.value)} required />
            <label>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
            <button type="submit" disabled={profileMutation.isPending}>
              {profileMutation.isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </form>
        </div>

        <div className="card">
          <h2>Change Password</h2>
          {passwordMessage && <Alert kind="success">{passwordMessage}</Alert>}
          {passwordError && <Alert kind="danger">{passwordError}</Alert>}
          <form
            onSubmit={(e: FormEvent) => {
              e.preventDefault();
              passwordMutation.mutate();
            }}
          >
            <label>Current password</label>
            <input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
            <label>New password</label>
            <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required />
            <label>Confirm new password</label>
            <input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required />
            <button type="submit" disabled={passwordMutation.isPending}>
              {passwordMutation.isPending ? 'Saving…' : 'Change Password'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
