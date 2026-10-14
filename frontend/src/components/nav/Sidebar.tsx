import { useEffect, useRef, useState, type RefObject } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useAuth } from '../../context/AuthContext';
import { useTheme } from '../../context/ThemeContext';
import { getPublerContext } from '../../api/publer';
import { switchWorkspace } from '../../api/mapping';
import { getNotifications, markAllNotificationsRead, markNotificationRead } from '../../api/notifications';
import Icon, { type IconName } from '../ui/Icon';
import { timeAgo } from '../../lib/datetime';

type NavItem = { to: string; label: string; icon: IconName };
type NavGroup = { label: string; items: NavItem[] };

/** Close a <details> popover on an outside click, Esc, or page change. */
function useDismissDetails(ref: RefObject<HTMLDetailsElement | null>) {
  const location = useLocation();
  useEffect(() => {
    ref.current?.removeAttribute('open');
  }, [location.pathname, ref]);
  useEffect(() => {
    const onDown = (e: MouseEvent) => {
      const el = ref.current;
      if (el?.open && !el.contains(e.target as Node)) el.removeAttribute('open');
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') ref.current?.removeAttribute('open');
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [ref]);
}

function NotificationBell() {
  const queryClient = useQueryClient();
  const ref = useRef<HTMLDetailsElement>(null);
  useDismissDetails(ref);
  const { data } = useQuery({ queryKey: ['notifications'], queryFn: getNotifications, refetchInterval: 30000 });

  const readMutation = useMutation({
    mutationFn: (id: number) => markNotificationRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  });
  const readAllMutation = useMutation({
    mutationFn: () => markAllNotificationsRead(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  });

  const notifications = data?.notifications ?? [];
  const unreadCount = data?.unread_count ?? 0;

  return (
    <details ref={ref} className="popover-wrap nav-notify">
      <summary className="icon-btn" title="Notifications" aria-label={`Notifications${unreadCount ? ` (${unreadCount} unread)` : ''}`}>
        <Icon name="bell" />
        {unreadCount > 0 && <span className="dot-badge">{unreadCount > 99 ? '99+' : unreadCount}</span>}
      </summary>
      <div className="popover popover-up nav-notify-menu">
        <div className="popover-head">
          <strong>Notifications</strong>
          {unreadCount > 0 && (
            <button type="button" className="link-btn" disabled={readAllMutation.isPending} onClick={() => readAllMutation.mutate()}>
              Mark all read
            </button>
          )}
        </div>
        {notifications.length === 0 ? (
          <div className="nav-notify-empty">You're all caught up.</div>
        ) : (
          notifications.map((n) => (
            <Link
              key={n.id}
              className={`nav-notify-item${n.is_read ? '' : ' unread'}`}
              to={n.task_id ? `/tasks/${n.task_id}` : '/tasks'}
              onClick={() => {
                if (!n.is_read) readMutation.mutate(n.id);
              }}
            >
              <span className="nav-notify-msg">{n.message}</span>
              <span className="nav-notify-time">{timeAgo(n.created_at)}</span>
            </Link>
          ))
        )}
      </div>
    </details>
  );
}

function WorkspaceSwitcher() {
  const queryClient = useQueryClient();
  const ref = useRef<HTMLDetailsElement>(null);
  useDismissDetails(ref);
  const { data } = useQuery({ queryKey: ['publer-context'], queryFn: getPublerContext, staleTime: 60000 });

  const switchMutation = useMutation({
    mutationFn: (workspaceId: string) => switchWorkspace(workspaceId),
    onSuccess: () => queryClient.invalidateQueries(),
  });

  if (!data || data.workspaces.length === 0) {
    return null;
  }
  const activeName = data.workspaces.find((w) => w.id === data.active_workspace_id)?.name ?? 'Workspace';
  const label = (
    <>
      <span className="ws-avatar">{activeName.charAt(0).toUpperCase()}</span>
      <span className="ws-text">
        <span className="ws-caption">Workspace</span>
        <span className="ws-name">{activeName}</span>
      </span>
    </>
  );

  if (data.workspaces.length === 1) {
    return <div className="workspace-card">{label}</div>;
  }

  return (
    <details ref={ref} className="popover-wrap workspace-switch">
      <summary className="workspace-card is-button">
        {label}
        <Icon name="chevronDown" size={16} className="ws-chevron" />
      </summary>
      <div className="popover workspace-switch-menu" role="listbox" aria-label="Switch workspace">
        {data.workspaces.map((ws) => {
          const active = ws.id === data.active_workspace_id;
          return (
            <button
              key={ws.id}
              type="button"
              role="option"
              aria-selected={active}
              className={`menu-item${active ? ' is-active' : ''}`}
              disabled={switchMutation.isPending}
              onClick={() => {
                ref.current?.removeAttribute('open');
                if (!active) switchMutation.mutate(ws.id);
              }}
            >
              <span className="ws-avatar ws-avatar-sm">{ws.name.charAt(0).toUpperCase()}</span>
              <span className="menu-item-label">{ws.name}</span>
              {active && <Icon name="check" size={16} className="menu-item-check" />}
            </button>
          );
        })}
      </div>
    </details>
  );
}

function AccountMenu({ onNavigate }: { onNavigate: () => void }) {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const ref = useRef<HTMLDetailsElement>(null);
  useDismissDetails(ref);
  if (!user) return null;

  return (
    <details ref={ref} className="popover-wrap account-menu">
      <summary className="account-chip">
        <span className="avatar">{user.name.charAt(0).toUpperCase()}</span>
        <span className="account-text">
          <span className="account-name">{user.name}</span>
          <span className="account-role">{user.role === 'admin' ? 'Administrator' : 'Editor'}</span>
        </span>
        <Icon name="chevronDown" size={16} className="ws-chevron" />
      </summary>
      <div className="popover popover-up">
        <Link className="menu-item" to="/profile" onClick={onNavigate}>
          <Icon name="user" size={17} /> <span className="menu-item-label">Profile</span>
        </Link>
        <button
          type="button"
          className="menu-item menu-item-danger"
          onClick={async () => {
            onNavigate();
            await logout();
            navigate('/login');
          }}
        >
          <Icon name="logout" size={17} /> <span className="menu-item-label">Log out</span>
        </button>
      </div>
    </details>
  );
}

export default function Sidebar() {
  const { user } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  if (!user) {
    return null;
  }

  const isAdmin = user.role === 'admin';
  const homePath = isAdmin ? '/dashboard' : '/editor-dashboard';
  const groups: NavGroup[] = [
    { label: 'Overview', items: [{ to: homePath, label: 'Dashboard', icon: 'dashboard' }] },
    {
      label: 'Create',
      items: [
        { to: '/publish', label: 'Publish to Websites', icon: 'publish' },
        { to: '/schedule', label: 'Schedule Posts', icon: 'schedule' },
      ],
    },
    {
      label: 'Queues',
      items: [
        { to: '/queue', label: 'Post Queue', icon: 'queue' },
        { to: '/publish-queue', label: 'Publish Queue', icon: 'publishQueue' },
        { to: '/jobs-status', label: 'Jobs Status', icon: 'clock' },
      ],
    },
    {
      label: isAdmin ? 'Manage' : 'Team',
      items: isAdmin
        ? [
            { to: '/sites', label: 'Websites', icon: 'globe' },
            { to: '/mapping', label: 'Map Websites', icon: 'link' },
            { to: '/categories', label: 'Categories', icon: 'tag' },
            { to: '/tasks', label: 'Tasks', icon: 'tasks' },
            { to: '/users', label: 'Users', icon: 'users' },
            { to: '/system', label: 'System', icon: 'settings' },
          ]
        : [{ to: '/tasks', label: 'Tasks', icon: 'tasks' }],
    },
  ];
  const close = () => setMobileOpen(false);

  return (
    <>
      <div className="mobile-topbar">
        <button type="button" className="icon-btn" onClick={() => setMobileOpen(true)} aria-label="Open menu">
          <Icon name="menu" />
        </button>
        <Link className="brand" to={homePath}>
          <span className="brand-mark">CP</span>
          <span className="brand-name">CreatePosts</span>
        </Link>
      </div>

      {mobileOpen && <div className="sidebar-backdrop" onClick={close} />}

      <aside className={`sidebar${mobileOpen ? ' is-open' : ''}`} aria-label="Main navigation">
        <div className="sidebar-brand">
          <Link className="brand" to={homePath} onClick={close}>
            <span className="brand-mark">CP</span>
            <span className="brand-name">CreatePosts</span>
          </Link>
          <button type="button" className="icon-btn sidebar-close" onClick={close} aria-label="Close menu">
            <Icon name="x" size={18} />
          </button>
        </div>

        <WorkspaceSwitcher />

        <nav className="sidebar-nav">
          {groups.map((g) => (
            <div className="nav-group" key={g.label}>
              <div className="nav-group-label">{g.label}</div>
              {g.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  className={({ isActive }) => `nav-link${isActive ? ' active' : ''}`}
                  onClick={close}
                >
                  <Icon name={item.icon} size={19} />
                  <span>{item.label}</span>
                </NavLink>
              ))}
            </div>
          ))}
        </nav>

        <div className="sidebar-footer">
          <AccountMenu onNavigate={close} />
          <div className="sidebar-tools">
            <NotificationBell />
            <button
              type="button"
              className="icon-btn"
              onClick={toggleTheme}
              title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
              aria-label="Toggle dark mode"
            >
              <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
