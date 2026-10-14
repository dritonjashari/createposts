import type { ReactNode } from 'react';

type AlertKind = 'success' | 'danger' | 'warning';

export default function Alert({ kind, children }: { kind: AlertKind; children: ReactNode }) {
  return <div className={`alert alert-${kind}`}>{children}</div>;
}
