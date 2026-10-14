import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { addTimeslot, removeTimeslot, type SystemContext } from '../../api/system';

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

interface Props {
  data: SystemContext;
  onSaved: (message: string) => void;
  onError: (err: unknown) => void;
}

export default function TimeslotsCard({ data, onSaved, onError }: Props) {
  const queryClient = useQueryClient();
  const [slotTime, setSlotTime] = useState('09:00');
  const [slotWorkspace, setSlotWorkspace] = useState('');
  const [slotDays, setSlotDays] = useState<number[]>([]);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['system'] });
  }

  const addSlotMutation = useMutation({
    mutationFn: () => addTimeslot(slotTime, slotWorkspace, slotDays),
    onSuccess: (res) => {
      onSaved(res.message);
      setSlotDays([]);
      invalidate();
    },
    onError,
  });
  const removeSlotMutation = useMutation({
    mutationFn: (id: number) => removeTimeslot(id),
    onSuccess: (res) => {
      onSaved(res.message);
      invalidate();
    },
    onError,
  });

  function toggleDay(d: number) {
    setSlotDays((prev) => (prev.includes(d) ? prev.filter((x) => x !== d) : [...prev, d].sort()));
  }

  return (
    <div className="card">
      <h2>Posting Timeslots</h2>
      <div className="timeslot-list">
        {data.timeslots.length === 0 && <p className="subtitle">No timeslots configured yet.</p>}
        {data.timeslots.map((slot) => (
          <span className="timeslot-chip" key={slot.id}>
            <span className="timeslot-time">{slot.time}</span>
            <small>{slot.days.length === 0 ? 'every day' : slot.days.map((d) => DAY_LABELS[d]).join(', ')}</small>
            <small>{slot.ws ? data.workspaces.find((w) => w.id === slot.ws)?.name ?? slot.ws : 'all workspaces'}</small>
            <button type="button" onClick={() => removeSlotMutation.mutate(slot.id)} title="Remove">
              ×
            </button>
          </span>
        ))}
      </div>
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          addSlotMutation.mutate();
        }}
      >
        <label>Time</label>
        <input type="time" value={slotTime} onChange={(e) => setSlotTime(e.target.value)} required />
        <label>Workspace</label>
        <select value={slotWorkspace} onChange={(e) => setSlotWorkspace(e.target.value)}>
          <option value="">All workspaces</option>
          {data.workspaces.map((ws) => (
            <option key={ws.id} value={ws.id}>
              {ws.name}
            </option>
          ))}
        </select>
        <label>Days</label>
        <div className="day-picker">
          {DAY_LABELS.map((label, idx) => (
            <label key={label}>
              <input type="checkbox" checked={slotDays.includes(idx)} onChange={() => toggleDay(idx)} />
              {label}
            </label>
          ))}
        </div>
        <p className="field-hint">No days selected = every day</p>
        <button type="submit" disabled={addSlotMutation.isPending}>
          {addSlotMutation.isPending ? 'Adding…' : 'Add Timeslot'}
        </button>
      </form>
    </div>
  );
}
