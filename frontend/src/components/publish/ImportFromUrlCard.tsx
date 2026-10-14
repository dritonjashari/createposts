import { useState, type KeyboardEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { importArticle, type ImportedArticle } from '../../api/publish';
import { ApiError } from '../../api/client';
import Alert from '../Alert';

function base64ToFile(base64: string, name: string, mime: string): File {
  const bin = atob(base64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new File([bytes], name, { type: mime });
}

interface Props {
  /** Apply the imported article to the form; return false if the user cancelled. */
  onImported: (article: ImportedArticle, image: File | null) => boolean;
}

/**
 * "Import from URL": paste a post link from another website and its title,
 * full text (every page of a paginated article) and featured image are
 * filled into the form below, ready to review and edit before publishing.
 */
export default function ImportFromUrlCard({ onImported }: Props) {
  const [url, setUrl] = useState('');
  const [summary, setSummary] = useState<{ text: string; warnings: string[] } | null>(null);

  const mutation = useMutation({
    mutationFn: (u: string) => importArticle(u),
    onSuccess: (article) => {
      const image = article.image ? base64ToFile(article.image.base64, article.image.name, article.image.mime) : null;
      if (!onImported(article, image)) {
        return;
      }
      const words = article.content.trim() === '' ? 0 : article.content.trim().split(/\s+/).length;
      const parts = [
        `${article.pages} page${article.pages === 1 ? '' : 's'}`,
        `${words.toLocaleString()} words`,
        image ? 'featured image added' : 'no featured image',
      ];
      setSummary({ text: `Imported: ${parts.join(' · ')}. Review the fields below before publishing.`, warnings: article.warnings });
    },
    onError: () => setSummary(null),
  });

  function start() {
    const u = url.trim();
    if (u === '' || mutation.isPending) return;
    setSummary(null);
    mutation.mutate(u);
  }

  // This card sits inside the publish <form>: Enter here must import, not publish.
  function onKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Enter') {
      e.preventDefault();
      start();
    }
  }

  return (
    <section className="card">
      <div className="publish-card-header">
        <div>
          <h2>Import from URL</h2>
          <p className="field-note">
            Paste the link of a post on another website. Its title, text (all pages, if the article is split into
            pages) and featured image are filled in below.
          </p>
        </div>
      </div>
      <div className="import-url-row">
        <input
          type="url"
          inputMode="url"
          aria-label="Post URL to import"
          placeholder="https://example.com/some-post/"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          onKeyDown={onKeyDown}
          disabled={mutation.isPending}
        />
        <button type="button" onClick={start} disabled={mutation.isPending || url.trim() === ''}>
          {mutation.isPending ? 'Importing…' : 'Import'}
        </button>
      </div>
      {mutation.isPending && <p className="field-note">Reading the article — multi-page posts can take a little longer.</p>}
      {mutation.isError && (
        <Alert kind="danger">
          {mutation.error instanceof ApiError ? mutation.error.message : 'The article could not be imported. Please try again.'}
        </Alert>
      )}
      {summary && (
        <>
          <Alert kind="success">{summary.text}</Alert>
          {summary.warnings.length > 0 && (
            <Alert kind="warning">
              {summary.warnings.map((w) => (
                <div key={w}>{w}</div>
              ))}
            </Alert>
          )}
        </>
      )}
    </section>
  );
}
