import type { FbPage } from '../../api/types';
import Icon from '../ui/Icon';

interface Props {
  showPagePicker: boolean;
  pages: FbPage[];
  accountIds: string[];
  allSelected: boolean;
  onToggleAccount: (id: string) => void;
  onToggleSelectAll: () => void;
  isPending: boolean;
}

export default function SelectPagesCard({
  showPagePicker,
  pages,
  accountIds,
  allSelected,
  onToggleAccount,
  onToggleSelectAll,
  isPending,
}: Props) {
  const count = showPagePicker ? accountIds.length : pages.length;
  return (
    <div className="card">
      <h2>Facebook pages</h2>
      {showPagePicker ? (
        pages.length > 0 ? (
          <>
            <div className="select-all-row">
              <label htmlFor="select-all-pages">
                <input type="checkbox" checked={allSelected} onChange={onToggleSelectAll} id="select-all-pages" />
                Select all
              </label>
              <span>
                {accountIds.length} / {pages.length}
              </span>
            </div>
            <div className="checkbox-group">
              {pages.map((page) => (
                <div className="checkbox-item" key={page.id}>
                  <label title={page.name}>
                    <input type="checkbox" checked={accountIds.includes(page.id)} onChange={() => onToggleAccount(page.id)} />
                    <span className="item-name">{page.name}</span>
                  </label>
                  {!page.connected && <span className="status-pill status-down">Disconnected</span>}
                </div>
              ))}
            </div>
          </>
        ) : (
          <p className="subtitle">No Facebook pages found in this workspace.</p>
        )
      ) : (
        <p className="small-text" style={{ marginBottom: 14 }}>
          This post goes to every Facebook page in the workspace ({pages.length}). Your admin can let you choose specific pages on the System page.
        </p>
      )}
      <button type="submit" disabled={isPending || count === 0}>
        <Icon name="send" size={16} />
        {isPending ? 'Scheduling…' : `Schedule to ${count} page${count === 1 ? '' : 's'}`}
      </button>
    </div>
  );
}
