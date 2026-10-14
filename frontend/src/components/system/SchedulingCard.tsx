import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { saveScheduling, type SystemContext } from '../../api/system';
import Toggle from '../ui/Toggle';

const COMMON_TIMEZONES = [
  'UTC', 'Europe/Berlin', 'Europe/London', 'Europe/Paris', 'Europe/Madrid', 'Europe/Rome', 'Europe/Amsterdam',
  'Europe/Warsaw', 'Europe/Moscow', 'Europe/Istanbul', 'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Lagos',
  'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Jakarta', 'Asia/Singapore',
  'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Tokyo', 'Asia/Seoul', 'Australia/Sydney', 'Australia/Perth',
  'Pacific/Auckland', 'America/Sao_Paulo', 'America/Argentina/Buenos_Aires', 'America/Mexico_City',
  'America/Bogota', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
  'America/Anchorage', 'Pacific/Honolulu',
];

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function SchedulingCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [timezone, setTimezone] = useState('Europe/Berlin');
  const [gapValue, setGapValue] = useState(0);
  const [gapUnit, setGapUnit] = useState<'minutes' | 'hours'>('minutes');
  const [imageFormat, setImageFormat] = useState('');
  const [firstPageId, setFirstPageId] = useState<Record<string, string>>({});
  const [defaultUseMapped, setDefaultUseMapped] = useState(true);
  const [mappedLinkTarget, setMappedLinkTarget] = useState<'comment1' | 'comment2'>('comment1');
  const [defaultParaphraseSocial, setDefaultParaphraseSocial] = useState(false);
  const [defaultParaphraseComment, setDefaultParaphraseComment] = useState(true);
  const [defaultParaphraseComment2, setDefaultParaphraseComment2] = useState(true);
  const [commentDelayMinutes, setCommentDelayMinutes] = useState(0);
  const [comment2DelayMinutes, setComment2DelayMinutes] = useState(0);
  const [defaultParaphraseWeb, setDefaultParaphraseWeb] = useState(false);
  const [autoCleanText, setAutoCleanText] = useState(false);
  const [editorsCanSchedule, setEditorsCanSchedule] = useState(true);
  const [autoDeleteEnabled, setAutoDeleteEnabled] = useState(false);
  const [autoDeleteHours, setAutoDeleteHours] = useState(24);
  const [defaultAddPixels, setDefaultAddPixels] = useState(true);

  useEffect(() => {
    setTimezone(data.timezone);
    setGapValue(data.gap_value);
    setGapUnit(data.gap_unit);
    setImageFormat(data.image_format);
    setFirstPageId(data.first_page_by_workspace);
    setDefaultUseMapped(data.default_use_mapped);
    setMappedLinkTarget(data.mapped_link_target);
    setDefaultParaphraseSocial(data.default_paraphrase_social);
    setDefaultParaphraseComment(data.default_paraphrase_comment);
    setDefaultParaphraseComment2(data.default_paraphrase_comment2);
    setCommentDelayMinutes(data.comment_delay_minutes);
    setComment2DelayMinutes(data.comment2_delay_minutes);
    setDefaultParaphraseWeb(data.default_paraphrase_web);
    setAutoCleanText(data.auto_clean_text);
    setEditorsCanSchedule(data.editors_can_schedule);
    setAutoDeleteEnabled(data.auto_delete_enabled);
    setAutoDeleteHours(data.auto_delete_hours);
    setDefaultAddPixels(data.default_add_pixels);
  }, [data]);

  const schedulingMutation = useMutation({
    mutationFn: () =>
      saveScheduling({
        gap_value: gapValue,
        gap_unit: gapUnit,
        image_format: imageFormat,
        timezone,
        first_page_id: firstPageId,
        default_use_mapped: defaultUseMapped,
        mapped_link_target: mappedLinkTarget,
        default_paraphrase_social: defaultParaphraseSocial,
        default_paraphrase_comment: defaultParaphraseComment,
        default_paraphrase_comment2: defaultParaphraseComment2,
        comment_delay_minutes: commentDelayMinutes,
        comment2_delay_minutes: comment2DelayMinutes,
        default_paraphrase_web: defaultParaphraseWeb,
        auto_clean_text: autoCleanText,
        editors_can_schedule: editorsCanSchedule,
        auto_delete_enabled: autoDeleteEnabled,
        auto_delete_hours: autoDeleteHours,
        default_add_pixels: defaultAddPixels,
      }),
    onSuccess: (res) => {
      onSaved(res.message);
      queryClient.invalidateQueries({ queryKey: ['system'] });
      queryClient.invalidateQueries({ queryKey: ['publer-context'] });
    },
    onError,
  });

  return (
    <div className="card">
      <h2>Scheduling</h2>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          schedulingMutation.mutate();
        }}
      >
        <div className="card-section-title">Timing</div>
        <div className="field-group">
          <label htmlFor="sc-tz">Timezone</label>
          <select id="sc-tz" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
            {COMMON_TIMEZONES.map((tz) => (
              <option key={tz} value={tz}>{tz}</option>
            ))}
          </select>
        </div>
        <div className="field-group">
          <label>Default gap between pages</label>
          <div className="gap-row">
            <input type="number" min={0} value={gapValue} onChange={(e) => setGapValue(Math.max(0, Number(e.target.value) || 0))} />
            <select value={gapUnit} onChange={(e) => setGapUnit(e.target.value as 'minutes' | 'hours')}>
              <option value="minutes">Minutes</option>
              <option value="hours">Hours</option>
            </select>
          </div>
        </div>
        {data.workspaces.map((ws) => (
          <div className="field-group" key={ws.id}>
            <label>First page for {ws.name}</label>
            <select value={firstPageId[ws.id] ?? ''} onChange={(e) => setFirstPageId((prev) => ({ ...prev, [ws.id]: e.target.value }))}>
              <option value="">— None —</option>
              {(data.pages_by_workspace[ws.id] ?? []).map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
            <span className="field-hint">Gets the base time; timeslots are checked against this page.</span>
          </div>
        ))}

        <div className="card-section-title">Social post defaults</div>
        <div className="toggle-list">
          <Toggle checked={defaultParaphraseSocial} onChange={setDefaultParaphraseSocial} label="Rewrite post text with AI" />
          <Toggle checked={defaultParaphraseComment} onChange={setDefaultParaphraseComment} label="Rewrite the first comment with AI" />
          <Toggle checked={defaultParaphraseComment2} onChange={setDefaultParaphraseComment2} label="Rewrite the second comment with AI" />
          <Toggle checked={defaultUseMapped} onChange={setDefaultUseMapped} label="Add the mapped website post link" />
        </div>
        <div className="field-group">
          <label htmlFor="sc-target">Put the website link in</label>
          <select id="sc-target" value={mappedLinkTarget} onChange={(e) => setMappedLinkTarget(e.target.value as 'comment1' | 'comment2')}>
            <option value="comment1">Comment 1 (first comment)</option>
            <option value="comment2">Comment 2 (second comment)</option>
          </select>
        </div>
        <div className="form-row">
          <div className="field-group">
            <label>Comment 1 delay (min)</label>
            <input type="number" min={0} value={commentDelayMinutes} onChange={(e) => setCommentDelayMinutes(Math.max(0, Number(e.target.value) || 0))} />
          </div>
          <div className="field-group">
            <label>Comment 2 delay (min)</label>
            <input type="number" min={0} value={comment2DelayMinutes} onChange={(e) => setComment2DelayMinutes(Math.max(0, Number(e.target.value) || 0))} />
          </div>
        </div>

        <div className="card-section-title">Images</div>
        <Toggle
          checked={defaultAddPixels}
          onChange={setDefaultAddPixels}
          label="Add random white pixels to photos"
          hint="Each Facebook page gets its own slightly different copy of the image. Can be changed per post."
        />
        <div className="field-group">
          <label htmlFor="sc-crop">Image crop ratio</label>
          <select id="sc-crop" value={imageFormat} onChange={(e) => setImageFormat(e.target.value)}>
            <option value="">Original</option>
            <option value="1:1">1:1 Square</option>
            <option value="4:5">4:5 Portrait</option>
            <option value="9:16">9:16 Story/Reel</option>
            <option value="16:9">16:9 Landscape</option>
          </select>
        </div>

        <div className="card-section-title">Website posts</div>
        <div className="toggle-list">
          <Toggle checked={defaultParaphraseWeb} onChange={setDefaultParaphraseWeb} label="Rewrite website posts with AI" />
          <Toggle checked={autoCleanText} onChange={setAutoCleanText} label="Auto-clean text on publish" hint="Removes ad markers, credits and stray links." />
        </div>

        <div className="card-section-title">Permissions &amp; clean-up</div>
        <div className="toggle-list">
          <Toggle
            checked={editorsCanSchedule}
            onChange={setEditorsCanSchedule}
            label="Editors can choose pages and use the Post Queue"
          />
          <Toggle
            checked={autoDeleteEnabled}
            onChange={setAutoDeleteEnabled}
            label="Auto-delete Facebook posts after a set time"
            hint="Default for new posts; can be changed per post."
          />
        </div>
        {autoDeleteEnabled && (
          <div className="field-group">
            <label>Delete posts after (hours)</label>
            <input
              type="number"
              min={1}
              max={8760}
              value={autoDeleteHours}
              onChange={(e) => setAutoDeleteHours(Math.min(8760, Math.max(1, Math.floor(Number(e.target.value) || 1))))}
            />
          </div>
        )}

        <button type="submit" disabled={schedulingMutation.isPending}>
          {schedulingMutation.isPending ? 'Saving…' : 'Save scheduling settings'}
        </button>
      </form>
    </div>
  );
}
