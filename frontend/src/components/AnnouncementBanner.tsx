import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getActiveAnnouncements } from '../api/announcements';

const DISMISSED_KEY = 'cp_dismissed_announcements';

function loadDismissed(): Set<number> {
  try {
    const raw = localStorage.getItem(DISMISSED_KEY);
    return raw ? new Set(JSON.parse(raw)) : new Set();
  } catch {
    return new Set();
  }
}

function saveDismissed(ids: Set<number>) {
  try {
    localStorage.setItem(DISMISSED_KEY, JSON.stringify([...ids]));
  } catch {
    // ignore — worst case the banner reappears next load
  }
}

export default function AnnouncementBanner() {
  const { data } = useQuery({
    queryKey: ['announcements-active'],
    queryFn: getActiveAnnouncements,
    staleTime: 5 * 60 * 1000,
    refetchInterval: 5 * 60 * 1000,
  });
  const [dismissed, setDismissed] = useState<Set<number>>(() => loadDismissed());

  // Re-sync if another tab dismisses one.
  useEffect(() => {
    function onStorage() {
      setDismissed(loadDismissed());
    }
    window.addEventListener('storage', onStorage);
    return () => window.removeEventListener('storage', onStorage);
  }, []);

  const announcements = (data?.announcements ?? []).filter((a) => !dismissed.has(a.id));
  if (announcements.length === 0) {
    return null;
  }

  function dismiss(id: number) {
    setDismissed((prev) => {
      const next = new Set(prev);
      next.add(id);
      saveDismissed(next);
      return next;
    });
  }

  return (
    <div className="announcement-stack">
      {announcements.map((a) => (
        <div key={a.id} className={`alert alert-${a.kind} announcement-banner`}>
          <span>{a.message}</span>
          <button
            type="button"
            className="announcement-dismiss"
            aria-label="Dismiss"
            onClick={() => dismiss(a.id)}
          >
            ×
          </button>
        </div>
      ))}
    </div>
  );
}
