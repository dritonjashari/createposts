import Segmented from '../ui/Segmented';
import Toggle from '../ui/Toggle';

type PostMode = 'schedule' | 'now' | 'timeslot';

interface Props {
  postMode: PostMode;
  onPostModeChange: (mode: PostMode) => void;
  hasTimeslots: boolean;
  scheduleTime: string;
  onScheduleTimeChange: (time: string) => void;
  nextTimeslotPreview: string | null | undefined;
  timezone?: string;
  isAdmin: boolean;
  pageGap: number;
  onPageGapChange: (gap: number) => void;
  pageGapUnit: 'minutes' | 'hours';
  onPageGapUnitChange: (unit: 'minutes' | 'hours') => void;
  autoDelete: boolean;
  onAutoDeleteChange: (on: boolean) => void;
  autoDeleteHours: number;
  onAutoDeleteHoursChange: (hours: number) => void;
}

export default function WhenCard({
  postMode,
  onPostModeChange,
  hasTimeslots,
  scheduleTime,
  onScheduleTimeChange,
  nextTimeslotPreview,
  timezone,
  isAdmin,
  pageGap,
  onPageGapChange,
  pageGapUnit,
  onPageGapUnitChange,
  autoDelete,
  onAutoDeleteChange,
  autoDeleteHours,
  onAutoDeleteHoursChange,
}: Props) {
  return (
    <div className="card">
      <h2>When</h2>
      <Segmented<PostMode>
        ariaLabel="When to post"
        value={postMode}
        onChange={onPostModeChange}
        options={[
          ...(hasTimeslots ? [{ value: 'timeslot' as const, label: 'Next free timeslot' }] : []),
          { value: 'schedule', label: 'Pick a time' },
          { value: 'now', label: 'Post now' },
        ]}
      />
      {postMode === 'schedule' && (
        <div className="field-group" style={{ marginTop: 12 }}>
          <input type="datetime-local" value={scheduleTime} onChange={(e) => onScheduleTimeChange(e.target.value)} required />
          {timezone && <span className="field-hint">Times are in {timezone}.</span>}
        </div>
      )}
      {postMode === 'timeslot' && (
        <p className="field-hint" style={{ marginTop: 10 }}>
          {nextTimeslotPreview ? (
            <>
              Next free slot: <strong>{nextTimeslotPreview}</strong>
            </>
          ) : (
            'No free timeslot found — all upcoming slots may be occupied in Publer.'
          )}
        </p>
      )}
      {isAdmin && postMode !== 'now' && (
        <div className="field-group" style={{ marginTop: 14 }}>
          <label>Gap between pages</label>
          <div className="gap-row">
            <input type="number" min={0} value={pageGap} onChange={(e) => onPageGapChange(Math.max(0, Number(e.target.value) || 0))} />
            <select value={pageGapUnit} onChange={(e) => onPageGapUnitChange(e.target.value as 'minutes' | 'hours')}>
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
            </select>
          </div>
        </div>
      )}
      <div className="media-options">
        <Toggle checked={autoDelete} onChange={onAutoDeleteChange} label="Auto-delete from Facebook later" />
        {autoDelete && (
          <div className="field-group" style={{ marginTop: 10, marginLeft: 50 }}>
            <div className="gap-row" style={{ alignItems: 'center' }}>
              <input
                type="number"
                min={1}
                max={8760}
                value={autoDeleteHours}
                onChange={(e) => onAutoDeleteHoursChange(Math.min(8760, Math.max(1, Math.floor(Number(e.target.value) || 1))))}
              />
              <span className="small-text" style={{ whiteSpace: 'nowrap' }}>hours after it goes live</span>
            </div>
            <p className="field-hint">
              Publer deletes the post from every selected page once it is {autoDeleteHours} hour{autoDeleteHours === 1 ? '' : 's'} old.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
