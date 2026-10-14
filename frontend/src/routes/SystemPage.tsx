import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getSystem } from '../api/system';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import WorkspaceCard from '../components/system/WorkspaceCard';
import PublerKeyCard from '../components/system/PublerKeyCard';
import SchedulingCard from '../components/system/SchedulingCard';
import TimeslotsCard from '../components/system/TimeslotsCard';
import OpenAiCard from '../components/system/OpenAiCard';
import SmtpCard from '../components/system/SmtpCard';
import AnnouncementsCard from '../components/system/AnnouncementsCard';
import LoadError from '../components/LoadError';

export default function SystemPage() {
  const { data, isLoading, error: loadError, refetch } = useQuery({ queryKey: ['system'], queryFn: getSystem });

  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  function ok(msg: string) {
    setMessage(msg);
    setError(null);
  }
  function fail(err: unknown) {
    setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
    setMessage(null);
  }

  if (isLoading || !data) {
    return (
      <div className="container">
        <h1>System</h1>
        {loadError ? (
          <LoadError error={loadError} onRetry={() => void refetch()} />
        ) : (
          <p className="subtitle">Loading…</p>
        )}
      </div>
    );
  }

  return (
    <div className="container container-wide">
      <h1>System</h1>
      <p className="subtitle">Configure workspaces, scheduling defaults, timeslots, and integrations.</p>
      {message && <Alert kind="success">{message}</Alert>}
      {error && <Alert kind="danger">{error}</Alert>}

      <div className="settings-sections-row">
        <section className="settings-section">
          <div className="settings-section-head">
            <p className="settings-section-title">Publer &amp; Scheduling</p>
            <p className="settings-section-desc">Your Facebook workspace, API key, and how posts get scheduled and spaced out.</p>
          </div>
          <div className="settings-grid">
            <WorkspaceCard data={data} onSaved={ok} onError={fail} />
            <PublerKeyCard data={data} onSaved={ok} onError={fail} />
            <SchedulingCard data={data} onSaved={ok} onError={fail} />
            <TimeslotsCard data={data} onSaved={ok} onError={fail} />
          </div>
        </section>

        <section className="settings-section">
          <div className="settings-section-head">
            <p className="settings-section-title">Content &amp; AI</p>
            <p className="settings-section-desc">Paraphrasing behavior and OpenAI usage for this account.</p>
          </div>
          <div className="settings-grid">
            <OpenAiCard data={data} onSaved={ok} onError={fail} />
          </div>
        </section>

        <section className="settings-section">
          <div className="settings-section-head">
            <p className="settings-section-title">Notifications</p>
            <p className="settings-section-desc">Email alerts for failures and banner announcements for your team.</p>
          </div>
          <div className="settings-grid">
            <SmtpCard data={data} onSaved={ok} onError={fail} />
            <AnnouncementsCard />
          </div>
        </section>
      </div>
    </div>
  );
}
