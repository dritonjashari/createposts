import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

const RELOAD_FLAG = 'cp_chunk_reload';

/** A page's JS chunk failed to download — typically a new build was deployed and the old file names are gone. */
function isChunkLoadError(error: Error): boolean {
  return /dynamically imported module|Importing a module script failed|Failed to fetch|ChunkLoadError/i.test(error.message);
}

/**
 * Catches a crash inside one page so it shows a message (with a way out)
 * instead of blanking the whole app. Keyed by route in App.tsx, so moving to
 * another page resets it.
 */
export default class PageErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Page crashed:', error, info.componentStack);
    if (isChunkLoadError(error)) {
      // Reload to pick up the new build — at most once per 10s, so a chunk
      // that is genuinely missing can't cause a reload loop.
      try {
        const last = Number(sessionStorage.getItem(RELOAD_FLAG) ?? 0);
        if (Date.now() - last > 10_000) {
          sessionStorage.setItem(RELOAD_FLAG, String(Date.now()));
          window.location.reload();
        }
      } catch {
        // storage unavailable — fall through to the message
      }
    }
  }

  render() {
    if (this.state.error) {
      return (
        <div className="container">
          <div className="alert alert-danger">
            This page ran into a problem: {this.state.error.message}{' '}
            <button type="button" className="btn-small" style={{ marginLeft: 8 }} onClick={() => window.location.reload()}>
              Reload
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
