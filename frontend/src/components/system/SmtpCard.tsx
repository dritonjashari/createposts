import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saveSmtp, type SystemContext } from '../../api/system';

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function SmtpCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [smtpHost, setSmtpHost] = useState('');
  const [smtpPort, setSmtpPort] = useState('587');
  const [smtpUser, setSmtpUser] = useState('');
  const [smtpPass, setSmtpPass] = useState('');
  const [smtpFrom, setSmtpFrom] = useState('');
  const [smtpFromName, setSmtpFromName] = useState('CreatePosts');
  const [smtpNotifyEmail, setSmtpNotifyEmail] = useState('');

  useEffect(() => {
    setSmtpHost(data.smtp.host);
    setSmtpPort(data.smtp.port || '587');
    setSmtpUser(data.smtp.user);
    setSmtpFrom(data.smtp.from);
    setSmtpFromName(data.smtp.from_name || 'CreatePosts');
    setSmtpNotifyEmail(data.smtp.notify_email);
  }, [data]);

  const smtpMutation = useMutation({
    mutationFn: () =>
      saveSmtp({
        host: smtpHost,
        port: smtpPort,
        user: smtpUser,
        pass: smtpPass,
        from: smtpFrom,
        from_name: smtpFromName,
        notify_email: smtpNotifyEmail,
      }),
    onSuccess: (res) => {
      onSaved(res.message);
      setSmtpPass('');
      queryClient.invalidateQueries({ queryKey: ['system'] });
    },
    onError,
  });

  return (
    <div className="card">
      <h2>Email Alerts (SMTP)</h2>
      <p className="field-hint" style={{ marginTop: 0 }}>
        Configure SMTP to receive an email when a publish job fails. Leave Host empty to disable alerts.
      </p>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          smtpMutation.mutate();
        }}
      >
        <label>SMTP Host</label>
        <input value={smtpHost} onChange={(e) => setSmtpHost(e.target.value)} placeholder="smtp.gmail.com" />
        <label>Port</label>
        <input value={smtpPort} onChange={(e) => setSmtpPort(e.target.value)} placeholder="587" />
        <label>Username</label>
        <input value={smtpUser} onChange={(e) => setSmtpUser(e.target.value)} placeholder="your@email.com" />
        <label>Password</label>
        <input
          type="password"
          value={smtpPass}
          onChange={(e) => setSmtpPass(e.target.value)}
          placeholder={data.smtp.pass_set ? '•••••••• (saved — type to replace)' : 'App password or SMTP password'}
          autoComplete="new-password"
        />
        <label>From address</label>
        <input value={smtpFrom} onChange={(e) => setSmtpFrom(e.target.value)} placeholder="alerts@yourdomain.com" />
        <label>From name</label>
        <input value={smtpFromName} onChange={(e) => setSmtpFromName(e.target.value)} placeholder="CreatePosts" />
        <label>Send failure alerts to</label>
        <input
          value={smtpNotifyEmail}
          onChange={(e) => setSmtpNotifyEmail(e.target.value)}
          placeholder="Leave empty to use your account email"
        />
        <p className="field-hint">If empty, alerts go to your account email ({data.openai ? '' : ''}
          <strong>{smtpNotifyEmail || 'your account email'}</strong>).
        </p>
        <button type="submit" disabled={smtpMutation.isPending}>
          {smtpMutation.isPending ? 'Saving…' : 'Save Email Settings'}
        </button>
      </form>
    </div>
  );
}
