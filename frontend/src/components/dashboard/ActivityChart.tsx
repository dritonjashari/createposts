import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { DashboardDay } from '../../api/dashboard';

// Stacked columns: website posts (series 1, bottom) + Facebook posts
// (series 2, top) per day. Palette slots validated for both themes with the
// dataviz validator (CVD ΔE ≥ 24, contrast ≥ 3:1 on the card surfaces).
// Marks: ≤ 24px columns, 2px surface gap between segments, 4px rounded data
// end, square at the baseline; hairline grid; only the peak day is labelled.

const PLOT_H = 190;
const AXIS_H = 26;
const TOP_PAD = 22; // room for the peak label
const LEFT = 34;

const TICK_COUNT = 4;

/** A round tick step (1, 2, 5 × 10ⁿ) so that TICK_COUNT steps cover v: ticks are always clean integers. */
function niceStep(v: number): number {
  const raw = Math.max(1, v / TICK_COUNT);
  const pow = 10 ** Math.floor(Math.log10(raw));
  for (const m of [1, 2, 5, 10]) {
    if (m * pow >= raw) return m * pow;
  }
  return 10 * pow;
}

function compact(n: number): string {
  return n >= 1000 ? `${(n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '')}K` : String(n);
}

function dayLabel(date: string, long: boolean): string {
  const d = new Date(`${date}T12:00:00Z`);
  return d.toLocaleDateString(undefined, long ? { weekday: 'short', month: 'short', day: 'numeric', timeZone: 'UTC' } : { month: 'short', day: 'numeric', timeZone: 'UTC' });
}

/** Column path with a rounded top (data end) and a square base. */
function columnPath(x: number, y: number, w: number, h: number, r: number): string {
  const rr = Math.min(r, h, w / 2);
  return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}

function useWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(0);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    setWidth(el.clientWidth);
    const ro = new ResizeObserver(() => setWidth(el.clientWidth));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, width] as const;
}

interface Props {
  series: DashboardDay[];
  /** Dim while a new period loads (keep the previous render, no layout jump). */
  stale?: boolean;
}

export default function ActivityChart({ series, stale }: Props) {
  const [wrapRef, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const [showTable, setShowTable] = useState(false);
  const tipRef = useRef<HTMLDivElement>(null);

  const totals = series.map((d) => d.web + d.social);
  const maxTotal = Math.max(0, ...totals);
  const step = niceStep(maxTotal);
  const yMax = step * TICK_COUNT;
  const peak = maxTotal > 0 ? totals.lastIndexOf(maxTotal) : -1;
  const n = series.length;
  const plotW = Math.max(0, width - LEFT - 4);
  const band = n > 0 ? plotW / n : 0;
  const barW = Math.max(4, Math.min(24, band * 0.62));
  const scale = (v: number) => (v / yMax) * PLOT_H;
  const ticks = Array.from({ length: TICK_COUNT + 1 }, (_, i) => i * step);
  // Day labels need ~52px each: skip some on narrow screens rather than let them collide.
  const labelEvery = Math.max(n <= 7 ? 1 : n <= 14 ? 2 : 5, band > 0 ? Math.ceil(52 / band) : 1);
  const allZero = maxTotal === 0 && series.every((d) => d.failed === 0);

  const hovered = hover !== null ? series[hover] : null;

  // Keep the tooltip inside the card.
  const tipLeft = useMemo(() => {
    if (hover === null) return 0;
    return LEFT + band * hover + band / 2;
  }, [hover, band]);
  const [tipShift, setTipShift] = useState(0);
  useEffect(() => {
    const tip = tipRef.current;
    if (!tip || hover === null) return;
    const w = tip.offsetWidth;
    const left = tipLeft - w / 2;
    setTipShift(left < 0 ? -left : left + w > width ? width - (left + w) : 0);
  }, [hover, tipLeft, width]);

  return (
    <div className="viz">
      <div className="viz-head">
        <div className="viz-legend" aria-hidden={showTable}>
          <span className="viz-key"><span className="viz-swatch" style={{ background: 'var(--series-1)' }} />Website posts</span>
          <span className="viz-key"><span className="viz-swatch" style={{ background: 'var(--series-2)' }} />Facebook posts</span>
        </div>
        <button type="button" className="btn-small btn-ghost" onClick={() => setShowTable((v) => !v)} aria-pressed={showTable}>
          {showTable ? 'Chart' : 'Table'}
        </button>
      </div>

      {showTable ? (
        <div className="table-wrap">
          <table className="posts-table">
            <thead>
              <tr><th>Day</th><th style={{ textAlign: 'right' }}>Website posts</th><th style={{ textAlign: 'right' }}>Facebook posts</th><th style={{ textAlign: 'right' }}>Failed</th></tr>
            </thead>
            <tbody>
              {[...series].reverse().map((d) => (
                <tr key={d.date}>
                  <td className="muted-cell">{dayLabel(d.date, true)}</td>
                  <td className="num-cell">{d.web}</td>
                  <td className="num-cell">{d.social}</td>
                  <td className="num-cell">{d.failed || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div ref={wrapRef} className={`viz-plot${stale ? ' is-stale' : ''}`} onMouseLeave={() => setHover(null)}>
          {width > 0 && (
            <svg width={width} height={TOP_PAD + PLOT_H + AXIS_H} role="img" aria-label={`Posts per day for the last ${n} days`}>
              <g transform={`translate(0, ${TOP_PAD})`}>
                {ticks.map((t) => {
                  const y = PLOT_H - scale(t);
                  return (
                    <g key={t}>
                      <line x1={LEFT} x2={width - 4} y1={y + 0.5} y2={y + 0.5} className={t === 0 ? 'viz-baseline' : 'viz-grid'} />
                      <text x={LEFT - 8} y={y + 4} textAnchor="end" className="viz-tick">{compact(t)}</text>
                    </g>
                  );
                })}

                {series.map((d, i) => {
                  const cx = LEFT + band * i + band / 2;
                  const x = cx - barW / 2;
                  const hWeb = scale(d.web);
                  const hSoc = scale(d.social);
                  const gap = hWeb > 0 && hSoc > 0 ? 2 : 0;
                  const webTop = d.social > 0;
                  return (
                    <g key={d.date} className={hover === i ? 'viz-col is-hover' : 'viz-col'}>
                      {d.web > 0 && (
                        <path
                          d={webTop ? `M${x},${PLOT_H}V${PLOT_H - hWeb}H${x + barW}V${PLOT_H}Z` : columnPath(x, PLOT_H - hWeb, barW, hWeb, 4)}
                          fill="var(--series-1)"
                        />
                      )}
                      {d.social > 0 && (
                        <path d={columnPath(x, PLOT_H - hWeb - gap - hSoc, barW, hSoc, 4)} fill="var(--series-2)" />
                      )}
                      {i === peak && (
                        <text x={cx} y={PLOT_H - hWeb - gap - hSoc - 7} textAnchor="middle" className="viz-peak">
                          {compact(totals[i])}
                        </text>
                      )}
                      {i % labelEvery === (n - 1) % labelEvery && (
                        <text x={cx} y={PLOT_H + 18} textAnchor="middle" className="viz-tick">
                          {n <= 7 ? `${dayLabel(d.date, true).split(',')[0]} ${Number(d.date.slice(8))}` : dayLabel(d.date, false)}
                        </text>
                      )}
                      {/* Hit target: the whole day band, bigger than the mark; focusable for keyboards. */}
                      <rect
                        x={LEFT + band * i}
                        y={-TOP_PAD}
                        width={band}
                        height={TOP_PAD + PLOT_H}
                        fill="transparent"
                        tabIndex={0}
                        role="img"
                        aria-label={`${dayLabel(d.date, true)}: ${d.web} website posts, ${d.social} Facebook posts${d.failed ? `, ${d.failed} failed` : ''}`}
                        onMouseEnter={() => setHover(i)}
                        onFocus={() => setHover(i)}
                        onBlur={() => setHover(null)}
                      />
                    </g>
                  );
                })}
              </g>
            </svg>
          )}
          {allZero && width > 0 && <div className="viz-empty">No posts in this period yet</div>}
          {hovered && (
            <div
              ref={tipRef}
              className="viz-tip"
              style={{ left: tipLeft + tipShift, top: Math.max(0, TOP_PAD + PLOT_H - scale(hovered.web + hovered.social) - 12) }}
            >
              <div className="viz-tip-title">{dayLabel(hovered.date, true)}</div>
              <div className="viz-tip-row"><span className="viz-tip-key" style={{ background: 'var(--series-1)' }} /><strong>{hovered.web}</strong> website</div>
              <div className="viz-tip-row"><span className="viz-tip-key" style={{ background: 'var(--series-2)' }} /><strong>{hovered.social}</strong> Facebook</div>
              {hovered.failed > 0 && <div className="viz-tip-row viz-tip-warn">⚠ <strong>{hovered.failed}</strong> failed</div>}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
