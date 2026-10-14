import type { ReactNode } from 'react';
import InpaintUploader from '../InpaintUploader';

// Mirrors the server-side cleanPostText() in lib/wordpress.php (used by the
// "Auto-clean text before publishing" checkbox) so the manual button strips
// the same ad markers, source/credit lines, and stray URLs.
const DROP_LINE_PATTERNS = [
  /^\s*(advertisement|advertisements|sponsored|sponsored content|ad)\s*$/i,
  /^\s*source\s*[:-].*/i,
  /[|(–—-]\s*(source|image source|photo source|photo credit|image credit|credit)\s*[:-].*/i,
  /^\s*(image|photo|picture|video)\s*(source|credit)?\s*[:-].*/i,
  /^\s*(caption|credit|photo credit|image source|via)\s*[:-].*/i,
  /^\s*(getty images|shutterstock|istock|reuters|ap photo|afp|©.*)\s*$/i,
  /^\s*https?:\/\/\S+\s*$/i,
  /^\s*\(.*\b(photo|image|source|credit|getty)\b.*\)\s*$/i,
  /^\s*(read more|read also|also read|related)\s*[:-].*/i,
];

function cleanPostText(text: string): string {
  const out = text
    .split(/\r\n|\r|\n/)
    .filter((line) => !DROP_LINE_PATTERNS.some((rx) => rx.test(line)))
    .map((line) => line.replace(/\b(advertisement|advertisements)\b\s*/gi, '').replace(/\*/g, ''));
  return out
    .join('\n')
    .replace(/[ \t]{2,}/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

interface Props {
  title: string;
  onTitleChange: (title: string) => void;
  content: string;
  onContentChange: (content: string) => void;
  image: File | null;
  imagePreview: string | null;
  onImageChange: (file: File | null) => void;
  isAdmin: boolean;
  cleanText: boolean;
  onCleanTextChange: (checked: boolean) => void;
  /** Extra card shown above the featured image (e.g. Import from URL). */
  before?: ReactNode;
}

export default function PostContentSection({
  title,
  onTitleChange,
  content,
  onContentChange,
  image,
  imagePreview,
  onImageChange,
  isAdmin,
  cleanText,
  onCleanTextChange,
  before,
}: Props) {
  return (
    <div className="publish-main">
      {before}
      <section className="card">
        <div className="publish-card-header">
          <div>
            <h2>Featured image</h2>
          </div>
        </div>
        <InpaintUploader image={image} imagePreview={imagePreview} onImageChange={onImageChange} />
      </section>

      <section className="card">
        <div className="publish-card-header">
          <div>
            <h2>Post content</h2>
            <p className="field-note">Enter your post title and content once, then publish to selected websites.</p>
          </div>
        </div>
        <div className="stack">
          <div className="field-group">
            <label htmlFor="publish-title">Title</label>
            <input
              id="publish-title"
              value={title}
              onChange={(e) => onTitleChange(e.target.value)}
              required
              placeholder="Post title"
            />
          </div>
          <div className="field-group">
            <label htmlFor="publish-content">Content</label>
            <textarea
              id="publish-content"
              rows={14}
              value={content}
              onChange={(e) => onContentChange(e.target.value)}
              required
              placeholder="Write the content for your WordPress posts here..."
            />
          </div>
        </div>
        <div className="publish-actions">
          <button type="button" className="btn-small btn-secondary" onClick={() => onContentChange(cleanPostText(content))}>
            ✨ Clean text
          </button>
          {isAdmin && (
            <label className="checkbox-label">
              <input type="checkbox" checked={cleanText} onChange={(e) => onCleanTextChange(e.target.checked)} />
              Auto-clean text before publishing
            </label>
          )}
        </div>
      </section>
    </div>
  );
}
