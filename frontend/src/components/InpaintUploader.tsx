import { useEffect, useRef, useState, type ChangeEvent, type DragEvent } from 'react';
import { useAuth } from '../context/AuthContext';

declare global {
  interface Window {
    Inpaint?: {
      init: (config: {
        apiKey: string;
        theme?: { primaryColor?: string; backgroundColor?: string; textColor?: string };
        features?: {
          showUploadButton?: boolean;
          showCompareButton?: boolean;
          showBrushSize?: boolean;
          showDownloadButton?: boolean;
          showClearButton?: boolean;
        };
      }) => void;
      open: (options: {
        image?: File | Blob | string;
        userId?: string;
        onResult: (blob: Blob) => void;
        onClose?: () => void;
        theme?: { primaryColor?: string; backgroundColor?: string; textColor?: string };
        features?: {
          showUploadButton?: boolean;
          showCompareButton?: boolean;
          showDownloadButton?: boolean;
          showClearButton?: boolean;
        };
      }) => void;
    };
  }
}

interface Props {
  image: File | null;
  imagePreview: string | null;
  onImageChange: (file: File | null) => void;
}

export default function InpaintUploader({ image, imagePreview, onImageChange }: Props) {
  const { user } = useAuth();
  const sdkContainerRef = useRef<HTMLDivElement>(null);
  const observerRef = useRef<MutationObserver | null>(null);
  const scrollLockObserverRef = useRef<MutationObserver | null>(null);
  // Mirrors sdkOpen for the unmount cleanup below: that effect has an empty
  // dep array (it must only run once, on unmount) so it would otherwise only
  // ever see the state as it was on mount, not whether the editor is open now.
  const sdkOpenRef = useRef(false);
  const [sdkReady, setSdkReady] = useState(false);
  const [sdkOpen, setSdkOpen] = useState(false);
  const [sdkError, setSdkError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    function tryInit() {
      if (!window.Inpaint) return false;
      window.Inpaint.init({
        apiKey: 'pk_live_1234',
        theme: { primaryColor: '#2563eb', backgroundColor: '#ffffff', textColor: '#111827' },
        features: {
          showUploadButton: true,
          showCompareButton: true,
          showBrushSize:true,
          showDownloadButton: false,
          showClearButton: true,
        },
      });
      setSdkReady(true);
      return true;
    }

    if (tryInit()) return;

    const script = document.querySelector<HTMLScriptElement>('script[src="https://deaschedule.com/sdk.js"]');
    if (!script) { setSdkError('Inpaint SDK script not found.'); return; }

    const onLoad = () => tryInit();
    const onError = () => setSdkError('Failed to load the Inpaint SDK.');
    script.addEventListener('load', onLoad);
    script.addEventListener('error', onError);
    return () => {
      script.removeEventListener('load', onLoad);
      script.removeEventListener('error', onError);
    };
  }, []);

  // Clean up on unmount (e.g. the user navigates to another page while the
  // editor is still open, without closing it via ESC or its close button).
  useEffect(() => () => {
    // Removing the SDK's DOM node out from under it (which is what happens
    // when React unmounts this component) doesn't tell the SDK it closed —
    // it still thinks it's open, so whatever keeps re-locking scroll behind
    // the scenes keeps running with nothing left to counter it (our own
    // observer is disconnected right below). Simulating the exact key it
    // already reacts to correctly (confirmed: pressing ESC always releases
    // the lock) makes it run its own real close/cleanup instead of guessing
    // at an undocumented close()/destroy() method on the SDK.
    if (sdkOpenRef.current) {
      for (const type of ['keydown', 'keyup'] as const) {
        document.dispatchEvent(new KeyboardEvent(type, { key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true }));
      }
    }
    observerRef.current?.disconnect();
    scrollLockObserverRef.current?.disconnect();
    document.body.style.removeProperty('overflow');
    document.documentElement.style.removeProperty('overflow');
  }, []);

  function openEditorWith(file?: File) {
    setSdkError(null);
    if (!window.Inpaint) { setSdkError('Inpaint SDK is not ready yet.'); return; }

    const container = sdkContainerRef.current;
    if (!container) return;

    // Disconnect any leftover observer from a previous open.
    observerRef.current?.disconnect();

    // Snapshot body children BEFORE calling open() so we only intercept the new element.
    const before = new Set(Array.from(document.body.children));

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
          if (!(node instanceof HTMLElement) || before.has(node)) continue;

          // Override position:fixed → absolute so it stays inside our container,
          // then fill the container completely. !important beats the SDK's own stylesheet.
          node.style.setProperty('position', 'absolute', 'important');
          node.style.setProperty('top',    '0',    'important');
          node.style.setProperty('left',   '0',    'important');
          node.style.setProperty('right',  '0',    'important');
          node.style.setProperty('bottom', '0',    'important');
          node.style.setProperty('width',  '100%', 'important');
          node.style.setProperty('height', '100%', 'important');

          container.appendChild(node);
          setSdkOpen(true);
          sdkOpenRef.current = true;
          observer.disconnect();
          observerRef.current = null;

          // The SDK sets overflow:hidden on <body>/<html> (inline style or a class)
          // to lock page scroll, as part of the same synchronous open() call that
          // inserted this node — which already happened by the time we get here, so
          // reacting to *future* mutations alone is too late. Force it back with
          // !important immediately, and keep watching in case it re-applies later.
          // !important is required because a class-based lock can't be undone by
          // removing an inline property — it needs a higher-specificity override.
          const restoreScroll = () => {
            document.body.style.setProperty('overflow', 'visible', 'important');
            document.documentElement.style.setProperty('overflow', 'visible', 'important');
          };
          restoreScroll();
          const slObs = new MutationObserver(restoreScroll);
          slObs.observe(document.body, { attributes: true, attributeFilter: ['style', 'class'] });
          slObs.observe(document.documentElement, { attributes: true, attributeFilter: ['style', 'class'] });
          scrollLockObserverRef.current = slObs;

          return;
        }
      }
    });

    observer.observe(document.body, { childList: true });
    observerRef.current = observer;

    window.Inpaint.open({
      image: file ?? image ?? undefined,
      userId: user ? `user_${user.id}` : undefined,
      onResult: (blob: Blob) => {
        // Save the result immediately but leave the SDK UI open — it stays visible
        // until the user closes it (ESC / close button), which fires onClose.
        onImageChange(new File([blob], 'featured-image.png', { type: 'image/png' }));
      },
      onClose: () => {
        scrollLockObserverRef.current?.disconnect();
        scrollLockObserverRef.current = null;
        document.body.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('overflow');
        setSdkOpen(false);
        sdkOpenRef.current = false;
      },
    });
  }

  function handleNewFile(file: File) {
    onImageChange(file);
    if (sdkReady) openEditorWith(file);
  }

  function handleDrop(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('image/')) handleNewFile(file);
  }

  function handleDragOver(e: DragEvent<HTMLDivElement>) {
    e.preventDefault();
    setDragOver(true);
  }

  function handleInputChange(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file) handleNewFile(file);
    e.target.value = '';
  }

  return (
    <div className="sdk-uploader">
      {/* SDK renders here when open — replaces the dropzone within the section */}
      <div ref={sdkContainerRef} className="sdk-container" style={{ display: sdkOpen ? 'block' : 'none' }} />

      {/* Normal dropzone / preview — hidden while SDK is open */}
      {!sdkOpen && (
        <div
          className={`sdk-preview-zone${dragOver ? ' sdk-drag-over' : ''}`}
          onDrop={handleDrop}
          onDragOver={handleDragOver}
          onDragLeave={() => setDragOver(false)}
          onDragEnd={() => setDragOver(false)}
          onClick={() => { if (!imagePreview) fileInputRef.current?.click(); }}
          style={!imagePreview ? { cursor: 'pointer' } : undefined}
        >
          {imagePreview ? (
            <img className="sdk-preview-img" src={imagePreview} alt="Featured image preview" />
          ) : (
            <div className="sdk-empty-state">
              <div className="sdk-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                  <rect x="3" y="3" width="18" height="18" rx="2" />
                  <circle cx="8.5" cy="8.5" r="1.5" />
                  <polyline points="21 15 16 10 5 21" />
                </svg>
              </div>
              <span className="sdk-empty-label">Drop image here or click to upload</span>
              <span className="sdk-empty-hint">Opens in the Inpaint editor automatically</span>
            </div>
          )}
        </div>
      )}

      <input ref={fileInputRef} type="file" accept="image/*" style={{ display: 'none' }} onChange={handleInputChange} />

      <div className="sdk-action-bar">
        <button
          type="button"
          className="sdk-open-btn"
          onClick={() => image ? openEditorWith() : fileInputRef.current?.click()}
          disabled={!sdkReady || sdkOpen}
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" width="17" height="17">
            <path d="M12 20h9" />
            <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z" />
          </svg>
          {!sdkReady ? 'Loading editor…' : image ? 'Edit image' : 'Upload & edit image'}
        </button>

        {image && !sdkOpen && (
          <button type="button" className="sdk-remove-btn" onClick={() => onImageChange(null)}>
            Remove
          </button>
        )}

        <span className="sdk-powered-badge">Powered by Inpaint</span>
      </div>

      {image && !sdkOpen && <p className="sdk-filename">{image.name}</p>}
      {sdkError && <p className="field-error">{sdkError}</p>}
    </div>
  );
}
