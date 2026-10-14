import type { Site } from '../../api/types';

interface Props {
  sites: Site[];
  selectedSites: number[];
  allSelected: boolean;
  onToggleSite: (id: number) => void;
  onToggleSelectAll: () => void;
}

export default function SelectSitesCard({ sites, selectedSites, allSelected, onToggleSite, onToggleSelectAll }: Props) {
  return (
    <section className="card">
      <div className="publish-card-header">
        <div>
          <h2>Select sites</h2>
          <p className="field-note">Choose one or more WordPress sites to publish this post to.</p>
        </div>
      </div>
      {sites.length > 0 ? (
        <>
          <div className="select-all-row">
            <label htmlFor="select-all-sites">
              <input type="checkbox" checked={allSelected} onChange={onToggleSelectAll} id="select-all-sites" />
              Select all
            </label>
            <span>
              {selectedSites.length} / {sites.length}
            </span>
          </div>
          <div className="checkbox-group" style={{ marginBottom: 0 }}>
            {sites.map((site) => (
              <div className="checkbox-item" key={site.id}>
                <label title={site.site_name}>
                  <input type="checkbox" checked={selectedSites.includes(site.id)} onChange={() => onToggleSite(site.id)} />
                  <span className="item-name">{site.site_name}</span>
                </label>
              </div>
            ))}
          </div>
        </>
      ) : (
        <p className="subtitle" style={{ marginBottom: 0 }}>No websites connected yet.</p>
      )}
    </section>
  );
}
