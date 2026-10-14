import { ApiError } from '../api/client';

interface Props {
  error: unknown;
  onRetry: () => void;
}

/** Shown in place of a page's content when its data failed to load. */
export default function LoadError({ error, onRetry }: Props) {
  const message = error instanceof ApiError || error instanceof Error ? error.message : 'Something went wrong.';
  return (
    <div className="alert alert-danger">
      Could not load this page: {message}{' '}
      <button type="button" className="btn-small" onClick={onRetry} style={{ marginLeft: 8 }}>
        Retry
      </button>
    </div>
  );
}
