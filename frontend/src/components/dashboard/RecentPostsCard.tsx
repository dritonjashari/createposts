import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getDashboardPosts } from '../../api/dashboard';
import Icon from '../ui/Icon';
import { formatDateTime } from '../../lib/datetime';

/** WordPress resolves ?p=<id> to the post's permalink whatever the permalink structure. */
function postLink(siteUrl: string | null, remotePostId: number): string | null {
  return siteUrl ? `${siteUrl.replace(/\/+$/, '')}/?p=${remotePostId}` : null;
}

/** Recent website posts, paged on its own so paging never reloads the statistics. */
export default function RecentPostsCard({ title, showPoster, timeZone }: { title: string; showPoster: boolean; timeZone: string }) {
  const [page, setPage] = useState(1);
  const { data, isFetching } = useQuery({ queryKey: ['dashboard-posts', page], queryFn: () => getDashboardPosts(page) });
  const posts = data?.posts ?? [];

  return (
    <div className="card">
      <h2>
        {title} {data && <small>({data.total.toLocaleString()})</small>}
      </h2>
      {data && posts.length === 0 ? (
        <div className="empty-state">
          <div className="empty-state-icon"><Icon name="publish" /></div>
          <strong>No website posts yet</strong>
          <span className="small-text">Posts published to your websites show up here.</span>
        </div>
      ) : (
        <>
          <div className={`table-wrap${isFetching && data ? ' is-stale' : ''}`}>
            <table className="posts-table">
              <thead>
                <tr>
                  <th>Title</th>
                  <th>Website</th>
                  <th>Status</th>
                  {showPoster && <th>Posted by</th>}
                  <th>When</th>
                </tr>
              </thead>
              <tbody>
                {posts.map((p) => {
                  const link = postLink(p.site_url, p.remote_post_id);
                  return (
                    <tr key={p.id}>
                      <td className="title-cell">
                        {link ? (
                          <a href={link} target="_blank" rel="noreferrer">{p.title}</a>
                        ) : (
                          p.title
                        )}
                      </td>
                      <td className="muted-cell">{p.site_name ?? 'Deleted site'}</td>
                      <td>
                        <span className={`badge badge-${p.status === 'publish' ? 'publish' : 'draft'}`}>{p.status === 'publish' ? 'Published' : p.status}</span>
                      </td>
                      {showPoster && <td className="muted-cell">{p.posted_by_name ?? '—'}</td>}
                      <td className="muted-cell">{formatDateTime(p.created_at, timeZone)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {data && data.total_pages > 1 && (
            <div className="pagination">
              <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                <Icon name="chevronLeft" size={16} /> Previous
              </button>
              <span className="page-info">Page {data.page} of {data.total_pages}</span>
              <button type="button" disabled={page >= data.total_pages} onClick={() => setPage((p) => p + 1)}>
                Next <Icon name="chevronRight" size={16} />
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
