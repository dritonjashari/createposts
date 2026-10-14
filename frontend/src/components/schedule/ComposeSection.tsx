import Toggle from '../ui/Toggle';
import Icon from '../ui/Icon';

interface Props {
  text: string;
  onTextChange: (text: string) => void;
  paraphrase: boolean;
  onParaphraseChange: (checked: boolean) => void;
  paraphraseComment: boolean;
  onParaphraseCommentChange: (checked: boolean) => void;
  paraphraseComment2: boolean;
  onParaphraseComment2Change: (checked: boolean) => void;
  tone: string;
  onToneChange: (tone: string) => void;
  customPrompt: string;
  onCustomPromptChange: (prompt: string) => void;
  useMappedPost: boolean;
  onUseMappedPostChange: (checked: boolean) => void;
  mappedLinkTarget: 'comment1' | 'comment2';
  showComment: boolean;
  onToggleShowComment: () => void;
  comment: string;
  onCommentChange: (comment: string) => void;
  commentDelayMinutes: number;
  onCommentDelayMinutesChange: (minutes: number) => void;
  showComment2: boolean;
  onToggleShowComment2: () => void;
  comment2: string;
  onComment2Change: (comment: string) => void;
  comment2DelayMinutes: number;
  onComment2DelayMinutesChange: (minutes: number) => void;
}

export default function ComposeSection(p: Props) {
  const anyParaphrase = p.paraphrase || p.paraphraseComment || p.paraphraseComment2;

  return (
    <div className="card">
      <div className="field-group">
        <label htmlFor="sch-text">Post text</label>
        <textarea id="sch-text" rows={8} value={p.text} onChange={(e) => p.onTextChange(e.target.value)} required placeholder="Write your Facebook post…" />
        <span className="field-hint" style={{ textAlign: 'right' }}>{p.text.length.toLocaleString()} characters</span>
      </div>

      <div className="toggle-list" style={{ marginTop: 6 }}>
        <Toggle
          checked={p.paraphrase}
          onChange={p.onParaphraseChange}
          label="Rewrite the text with AI"
          hint="Every page gets its own version of the text."
        />
        <Toggle
          checked={p.useMappedPost}
          onChange={p.onUseMappedPostChange}
          label="Add the mapped website post link"
          hint={`Goes into ${p.mappedLinkTarget === 'comment2' ? 'the second comment' : 'the first comment'} — change this on the System page.`}
        />
      </div>

      {anyParaphrase && (
        <div className="field-group" style={{ marginTop: 16 }}>
          <label htmlFor="sch-tone">AI tone</label>
          <select id="sch-tone" value={p.tone} onChange={(e) => p.onToneChange(e.target.value)}>
            <option value="neutral">Neutral</option>
            <option value="professional">Professional</option>
            <option value="friendly">Friendly</option>
            <option value="persuasive">Persuasive</option>
            <option value="custom">Custom prompt</option>
          </select>
          {p.tone === 'custom' && (
            <textarea
              value={p.customPrompt}
              onChange={(e) => p.onCustomPromptChange(e.target.value)}
              rows={3}
              style={{ marginTop: 6 }}
              placeholder="Your rewriting instructions — the content is appended automatically."
            />
          )}
          <span className="field-hint">Used for the text and for any comment set to be rewritten.</span>
        </div>
      )}

      <div className="card-section-title" style={{ marginTop: 22 }}>Comments</div>
      {!p.showComment ? (
        <button type="button" className="btn-small btn-secondary" onClick={p.onToggleShowComment}>
          <Icon name="plus" size={14} /> Add first comment
        </button>
      ) : (
        <div className="field-group" style={{ gap: 10 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <label htmlFor="sch-c1" style={{ margin: 0 }}>First comment</label>
            <button type="button" className="link-btn" onClick={p.onToggleShowComment}>Hide</button>
          </div>
          <textarea id="sch-c1" rows={3} value={p.comment} onChange={(e) => p.onCommentChange(e.target.value)} placeholder="e.g. PART 2: …" />
          <div className="gap-row" style={{ alignItems: 'center', maxWidth: 360 }}>
            <span className="small-text" style={{ whiteSpace: 'nowrap' }}>Post it after</span>
            <input type="number" min={0} value={p.commentDelayMinutes} onChange={(e) => p.onCommentDelayMinutesChange(Math.max(0, Number(e.target.value) || 0))} />
            <span className="small-text" style={{ whiteSpace: 'nowrap' }}>min (0 = at once)</span>
          </div>
          <Toggle checked={p.paraphraseComment} onChange={p.onParaphraseCommentChange} label="Rewrite this comment with AI" hint="Off keeps it identical on every page." />

          {!p.showComment2 ? (
            <button type="button" className="btn-small btn-secondary" style={{ alignSelf: 'flex-start', marginTop: 6 }} onClick={p.onToggleShowComment2}>
              <Icon name="plus" size={14} /> Add second comment
            </button>
          ) : (
            <div className="field-group" style={{ gap: 10, marginTop: 8, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <label htmlFor="sch-c2" style={{ margin: 0 }}>Second comment</label>
                <button type="button" className="link-btn" onClick={p.onToggleShowComment2}>Hide</button>
              </div>
              <textarea id="sch-c2" rows={3} value={p.comment2} onChange={(e) => p.onComment2Change(e.target.value)} placeholder="e.g. FULL STORY >> link" />
              <div className="gap-row" style={{ alignItems: 'center', maxWidth: 360 }}>
                <span className="small-text" style={{ whiteSpace: 'nowrap' }}>Post it after</span>
                <input type="number" min={0} value={p.comment2DelayMinutes} onChange={(e) => p.onComment2DelayMinutesChange(Math.max(0, Number(e.target.value) || 0))} />
                <span className="small-text" style={{ whiteSpace: 'nowrap' }}>min (0 = at once)</span>
              </div>
              <Toggle checked={p.paraphraseComment2} onChange={p.onParaphraseComment2Change} label="Rewrite this comment with AI" hint="Off keeps it identical on every page." />
            </div>
          )}
        </div>
      )}
    </div>
  );
}
