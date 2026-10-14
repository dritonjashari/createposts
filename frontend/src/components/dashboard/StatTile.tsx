import type { ReactNode } from 'react';
import Icon, { type IconName } from '../ui/Icon';

interface Props {
  label: string;
  value: ReactNode;
  icon: IconName;
  accent: string;
  /** Change vs the previous period. */
  delta?: { text: string; direction: 'up' | 'down' | 'flat'; good: boolean } | null;
  sub?: ReactNode;
}

/** Change between two counts as a tile delta ("+12%", "new", "no change"). */
export function countDelta(current: number, previous: number, periodLabel: string): Props['delta'] {
  if (current === 0 && previous === 0) return null;
  if (previous === 0) return { text: `new vs ${periodLabel}`, direction: 'up', good: true };
  const pct = Math.round(((current - previous) / previous) * 100);
  if (pct === 0) return { text: `no change vs ${periodLabel}`, direction: 'flat', good: true };
  return { text: `${pct > 0 ? '+' : ''}${pct}% vs ${periodLabel}`, direction: pct > 0 ? 'up' : 'down', good: pct > 0 };
}

/** Change of a percentage, in percentage points. */
export function pointsDelta(current: number | null, previous: number | null, periodLabel: string): Props['delta'] {
  if (current === null || previous === null) return null;
  const pts = Math.round((current - previous) * 10) / 10;
  if (pts === 0) return { text: `no change vs ${periodLabel}`, direction: 'flat', good: true };
  return { text: `${pts > 0 ? '+' : ''}${pts} pts vs ${periodLabel}`, direction: pts > 0 ? 'up' : 'down', good: pts > 0 };
}

export default function StatTile({ label, value, icon, accent, delta, sub }: Props) {
  return (
    <div className="stat-card" style={{ ['--accent' as string]: accent }}>
      <span className="stat-icon">
        <Icon name={icon} size={18} />
      </span>
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
      {delta && (
        <span className={`stat-delta ${delta.direction === 'flat' ? 'is-flat' : delta.good ? 'is-good' : 'is-bad'}`}>
          {delta.direction === 'up' ? '▲' : delta.direction === 'down' ? '▼' : '•'} {delta.text}
        </span>
      )}
      {sub && <span className="stat-sub">{sub}</span>}
    </div>
  );
}
