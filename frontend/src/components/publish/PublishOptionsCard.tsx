import type { Category } from '../../api/types';
import Toggle from '../ui/Toggle';
import Icon from '../ui/Icon';

interface Props {
  categories: Category[];
  categoryId: string;
  onCategoryIdChange: (id: string) => void;
  status: 'draft' | 'publish';
  onStatusChange: (status: 'draft' | 'publish') => void;
  paraphrase: boolean;
  onParaphraseChange: (checked: boolean) => void;
  tone: string;
  onToneChange: (tone: string) => void;
  customPrompt: string;
  onCustomPromptChange: (prompt: string) => void;
  isPending: boolean;
  /** Number of sites selected — shown on the button. */
  siteCount: number;
}

export default function PublishOptionsCard({
  categories,
  categoryId,
  onCategoryIdChange,
  status,
  onStatusChange,
  paraphrase,
  onParaphraseChange,
  tone,
  onToneChange,
  customPrompt,
  onCustomPromptChange,
  isPending,
  siteCount,
}: Props) {
  return (
    <section className="card">
      <div className="publish-card-header">
        <div>
          <h2>Publish options</h2>
          <p className="field-note">Fine-tune how this post appears on connected WordPress sites.</p>
        </div>
      </div>
      <div className="stack">
        <div className="field-group">
          <label htmlFor="pub-category">Category</label>
          <select id="pub-category" value={categoryId} onChange={(e) => onCategoryIdChange(e.target.value)}>
            <option value="0">None</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>
                {cat.name}
              </option>
            ))}
          </select>
        </div>
        <div className="field-group">
          <label htmlFor="pub-status">Final status after processing</label>
          <select id="pub-status" value={status} onChange={(e) => onStatusChange(e.target.value as 'draft' | 'publish')}>
            <option value="publish">Publish</option>
            <option value="draft">Keep as Draft</option>
          </select>
        </div>
        <Toggle
          checked={paraphrase}
          onChange={onParaphraseChange}
          label="Paraphrase content with AI"
          hint="Every website gets its own version of the text."
        />
        {paraphrase && (
          <div className="field-group">
            <label htmlFor="pub-tone">Paraphrase tone</label>
            <select id="pub-tone" value={tone} onChange={(e) => onToneChange(e.target.value)}>
              <option value="neutral">Neutral</option>
              <option value="professional">Professional</option>
              <option value="friendly">Friendly</option>
              <option value="persuasive">Persuasive</option>
              <option value="custom">Custom Prompt</option>
            </select>
            {tone === 'custom' && (
              <textarea
                value={customPrompt}
                onChange={(e) => onCustomPromptChange(e.target.value)}
                rows={3}
                style={{ marginTop: 6 }}
                placeholder="Enter your rewriting instructions. The content will be appended automatically."
              />
            )}
          </div>
        )}
      </div>
      <button type="submit" className="card-submit" disabled={isPending || siteCount === 0}>
        <Icon name="send" size={16} />
        {isPending ? 'Publishing…' : siteCount > 0 ? `Publish to ${siteCount} site${siteCount === 1 ? '' : 's'}` : 'Select a site to publish'}
      </button>
    </section>
  );
}
