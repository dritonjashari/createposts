import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { createPublish, getPublishContext, type ImportedArticle } from '../api/publish';
import { ApiError } from '../api/client';
import Alert from '../components/Alert';
import JobProgress from '../components/publish/JobProgress';
import PostContentSection from '../components/publish/PostContentSection';
import ImportFromUrlCard from '../components/publish/ImportFromUrlCard';
import PublishOptionsCard from '../components/publish/PublishOptionsCard';
import SelectSitesCard from '../components/publish/SelectSitesCard';
import { useSharedMedia } from '../context/SharedMediaContext';

export default function PublishPage() {
  const { data: context, isLoading } = useQuery({ queryKey: ['publish-context'], queryFn: getPublishContext });
  const { setSharedFile } = useSharedMedia();

  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [cleanText, setCleanText] = useState(false);
  const [status, setStatus] = useState<'draft' | 'publish'>('publish');
  const [paraphrase, setParaphrase] = useState(false);
  const [tone, setTone] = useState('neutral');
  const [customPrompt, setCustomPrompt] = useState('');
  const [categoryId, setCategoryId] = useState('0');
  const [selectedSites, setSelectedSites] = useState<number[]>([]);
  const [image, setImage] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [jobId, setJobId] = useState<number | null>(null);
  const [syncResult, setSyncResult] = useState<{ success_count: number; success_sites: string[]; failures: string[] } | null>(null);
  const allSelected = context ? selectedSites.length === context.sites.length && context.sites.length > 0 : false;

  function toggleSite(id: number) {
    setSelectedSites((prev) => (prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id]));
  }

  function toggleSelectAll() {
    if (!context) return;
    setSelectedSites(allSelected ? [] : context.sites.map((s) => s.id));
  }

  function handleFile(file: File | null) {
    if (imagePreview?.startsWith('blob:')) {
      URL.revokeObjectURL(imagePreview);
    }
    setImage(file);
    setSharedFile(file);
    if (file) {
      setImagePreview(URL.createObjectURL(file));
    } else {
      setImagePreview(null);
    }
  }

  function applyImport(article: ImportedArticle, importedImage: File | null): boolean {
    const hasWork = title.trim() !== '' || content.trim() !== '' || image !== null;
    if (hasWork && !confirm('Replace the current title, content and featured image with the imported article?')) {
      return false;
    }
    setTitle(article.title);
    setContent(article.content);
    // Keep the user's own image if the source page had none.
    if (importedImage || !hasWork) {
      handleFile(importedImage);
    }
    setError(null);
    return true;
  }

  const publishMutation = useMutation({
    mutationFn: (formData: FormData) => createPublish(formData),
    onSuccess: (res) => {
      setError(null);
      if (res.job_id) {
        setJobId(res.job_id);
      } else {
        setSyncResult({
          success_count: res.success_count ?? 0,
          success_sites: res.success_sites ?? [],
          failures: res.failures ?? [],
        });
      }
    },
    onError: (err) => {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
    },
  });

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (selectedSites.length === 0) {
      setError('Please choose at least one site.');
      return;
    }
    if (!confirm(`Publish "${title || 'this post'}" to ${selectedSites.length} site(s)?`)) {
      return;
    }
    const fd = new FormData();
    selectedSites.forEach((id) => fd.append('site_ids[]', String(id)));
    fd.append('title', title);
    fd.append('content', content);
    if (context?.is_admin && cleanText) fd.append('clean_text', '1');
    fd.append('status', status);
    if (paraphrase) fd.append('paraphrase', '1');
    fd.append('paraphrase_tone', tone);
    if (tone === 'custom') fd.append('paraphrase_custom_prompt', customPrompt);
    fd.append('category_id', categoryId);
    if (image) fd.append('featured_image', image);
    publishMutation.mutate(fd);
  }

  useEffect(() => {
    if (context) {
      setParaphrase(context.default_paraphrase_web);
      if (context.is_admin) setCleanText(context.auto_clean_default);
      setSelectedSites(context.sites.map((s) => s.id));
    }
  }, [context]);

  useEffect(() => {
    return () => {
      if (imagePreview?.startsWith('blob:')) {
        URL.revokeObjectURL(imagePreview);
      }
    };
  }, [imagePreview]);

  function resetForm() {
    setJobId(null);
    setSyncResult(null);
    setTitle('');
    setContent('');
    setImage(null);
    setImagePreview(null);
    setParaphrase(context?.default_paraphrase_web ?? false);
    if (context?.is_admin) setCleanText(context.auto_clean_default);
  }

  const categories = useMemo(() => context?.categories ?? [], [context]);

  if (jobId !== null) {
    return (
      <div className="container container-wide">
        <h1>Publish to Websites</h1>
        <JobProgress jobId={jobId} onDone={resetForm} onRetried={setJobId} />
      </div>
    );
  }

  if (syncResult) {
    return (
      <div className="container container-wide">
        <h1>Publish to Websites</h1>
        <div className="card">
          <h2>Done</h2>
          <p>
            Published to {syncResult.success_count} site(s){syncResult.success_sites.length > 0 ? `: ${syncResult.success_sites.join(', ')}` : ''}.
          </p>
          {syncResult.failures.length > 0 && (
            <ul className="list">
              {syncResult.failures.map((f) => (
                <li key={f}>{f}</li>
              ))}
            </ul>
          )}
          <button type="button" onClick={resetForm}>
            Publish another
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="container container-wide">
      <h1>Publish to Websites</h1>
      <p className="subtitle">Write once and publish to any connected WordPress site with modern publishing controls.</p>
      {error && <Alert kind="danger">{error}</Alert>}
      {isLoading ? (
        <p className="subtitle">Loading…</p>
      ) : (
        <form onSubmit={handleSubmit}>
          <div className="publish-layout">
            <PostContentSection
              title={title}
              onTitleChange={setTitle}
              content={content}
              onContentChange={setContent}
              image={image}
              imagePreview={imagePreview}
              onImageChange={handleFile}
              isAdmin={!!context?.is_admin}
              cleanText={cleanText}
              onCleanTextChange={setCleanText}
              before={<ImportFromUrlCard onImported={applyImport} />}
            />

            <aside className="publish-sidebar">
              <PublishOptionsCard
                categories={categories}
                categoryId={categoryId}
                onCategoryIdChange={setCategoryId}
                status={status}
                onStatusChange={setStatus}
                paraphrase={paraphrase}
                onParaphraseChange={setParaphrase}
                tone={tone}
                onToneChange={setTone}
                customPrompt={customPrompt}
                onCustomPromptChange={setCustomPrompt}
                isPending={publishMutation.isPending}
                siteCount={selectedSites.length}
              />

              <SelectSitesCard
                sites={context?.sites ?? []}
                selectedSites={selectedSites}
                allSelected={allSelected}
                onToggleSite={toggleSite}
                onToggleSelectAll={toggleSelectAll}
              />
            </aside>
          </div>
        </form>
      )}
    </div>
  );
}
