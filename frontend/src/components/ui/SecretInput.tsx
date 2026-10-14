import { useState } from 'react';

interface Props {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  id?: string;
}

/** API keys are hidden by default (shoulder-surfing, screen sharing) with a Show/Hide button. */
export default function SecretInput({ value, onChange, placeholder, id }: Props) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="secret-row">
      <input
        id={id}
        type={visible ? 'text' : 'password'}
        autoComplete="off"
        spellCheck={false}
        value={value}
        placeholder={placeholder}
        onChange={(e) => onChange(e.target.value)}
      />
      <button type="button" className="btn-secondary" onClick={() => setVisible((v) => !v)} style={{ width: 'auto', marginTop: 0 }}>
        {visible ? 'Hide' : 'Show'}
      </button>
    </div>
  );
}
