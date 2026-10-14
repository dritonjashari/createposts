// Dates are shown — and typed into datetime inputs — in the APP's timezone
// (System page setting), because that's the zone the server reads them in.
// Using the browser's own zone instead shifted every reschedule by the
// difference whenever someone worked from another timezone.

/** "Tue, Oct 13, 18:00" in the given timezone. */
export function formatDateTime(iso: string | null | undefined, timeZone?: string): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: timeZone || undefined,
  });
}

/** Value for <input type="datetime-local"> (YYYY-MM-DDTHH:MM) in the given timezone. */
export function toDateTimeInput(iso: string | Date | null | undefined, timeZone?: string): string {
  if (!iso) return '';
  const d = iso instanceof Date ? iso : new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  // sv-SE formats as "2026-10-13 18:00".
  const s = new Intl.DateTimeFormat('sv-SE', {
    timeZone: timeZone || undefined,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(d);
  return s.replace(' ', 'T');
}

/** "5m ago", "3h ago", "Oct 2". */
export function timeAgo(iso: string): string {
  const diff = (Date.now() - new Date(iso).getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
