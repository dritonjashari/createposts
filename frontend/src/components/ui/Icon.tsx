import type { ReactNode, SVGProps } from 'react';

// Minimal line-icon set (no icon-library dependency). 24×24 grid, stroke icons.
function Svg({ children, size = 20, ...rest }: SVGProps<SVGSVGElement> & { children: ReactNode; size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      {...rest}
    >
      {children}
    </svg>
  );
}

const paths: Record<string, ReactNode> = {
  dashboard: (
    <>
      <rect x="3" y="3" width="7.5" height="7.5" rx="1.5" />
      <rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5" />
      <rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5" />
      <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5" />
    </>
  ),
  publish: (
    <>
      <line x1="12" y1="19" x2="12" y2="6" />
      <polyline points="5.5 12.5 12 6 18.5 12.5" />
      <line x1="5" y1="20" x2="19" y2="20" />
    </>
  ),
  publishQueue: (
    <>
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <line x1="8" y1="8" x2="16" y2="8" />
      <polyline points="7.5 13 9.5 15 13.5 11" />
      <line x1="8" y1="17.5" x2="13" y2="17.5" />
    </>
  ),
  schedule: (
    <>
      <rect x="3" y="4.5" width="18" height="16" rx="2" />
      <line x1="3" y1="9.5" x2="21" y2="9.5" />
      <line x1="8" y1="2.5" x2="8" y2="6.5" />
      <line x1="16" y1="2.5" x2="16" y2="6.5" />
    </>
  ),
  queue: (
    <>
      <line x1="4" y1="7" x2="20" y2="7" />
      <line x1="4" y1="12" x2="20" y2="12" />
      <line x1="4" y1="17" x2="14" y2="17" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <polyline points="12 7 12 12 15.5 13.8" />
    </>
  ),
  tasks: (
    <>
      <polyline points="4 7 6 9 9.5 5" />
      <line x1="12.5" y1="7" x2="20" y2="7" />
      <polyline points="4 16 6 18 9.5 14" />
      <line x1="12.5" y1="16" x2="20" y2="16" />
    </>
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <line x1="3" y1="12" x2="21" y2="12" />
      <path d="M12 3c2.6 3 2.6 15 0 18" />
      <path d="M12 3c-2.6 3-2.6 15 0 18" />
    </>
  ),
  tag: (
    <>
      <path d="M11.5 3H5a1 1 0 0 0-1 1v6.5a1 1 0 0 0 .3.7l9 9a1 1 0 0 0 1.4 0l6.5-6.5a1 1 0 0 0 0-1.4l-9-9a1 1 0 0 0-.7-.3Z" />
      <circle cx="7.7" cy="7.7" r="1.1" fill="currentColor" stroke="none" />
    </>
  ),
  link: (
    <>
      <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1.5 1.5" />
      <path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1.5-1.5" />
    </>
  ),
  settings: (
    <>
      <line x1="4" y1="6.5" x2="20" y2="6.5" />
      <circle cx="9.5" cy="6.5" r="2" fill="currentColor" stroke="none" />
      <line x1="4" y1="12" x2="20" y2="12" />
      <circle cx="15" cy="12" r="2" fill="currentColor" stroke="none" />
      <line x1="4" y1="17.5" x2="20" y2="17.5" />
      <circle cx="7.5" cy="17.5" r="2" fill="currentColor" stroke="none" />
    </>
  ),
  users: (
    <>
      <circle cx="9" cy="8" r="3" />
      <path d="M3.5 20c0-3.3 2.5-6 5.5-6s5.5 2.7 5.5 6" />
      <circle cx="17.5" cy="8.5" r="2.3" />
      <path d="M14.8 20c.2-2.4 1.6-4 3.4-4.2" />
    </>
  ),
  user: (
    <>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4.2 3.6-7.5 8-7.5s8 3.3 8 7.5" />
    </>
  ),
  bell: (
    <>
      <path d="M12 3c-1.9 0-3.4 1.6-3.4 3.5v3.2c0 1.4-.6 2.8-1.6 3.8L6 14.8h12l-1-1.3c-1-1-1.6-2.4-1.6-3.8V6.5C15.4 4.6 13.9 3 12 3Z" />
      <path d="M10 18.5a2 2 0 0 0 4 0" />
    </>
  ),
  logout: (
    <>
      <path d="M9.5 21H5.5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
      <polyline points="15.5 16.5 20.5 12 15.5 7.5" />
      <line x1="20.5" y1="12" x2="9.5" y2="12" />
    </>
  ),
  chevronDown: <polyline points="6 9 12 15 18 9" />,
  chevronRight: <polyline points="9 6 15 12 9 18" />,
  chevronLeft: <polyline points="15 6 9 12 15 18" />,
  check: <polyline points="5 13 10 18 19 7" />,
  x: (
    <>
      <line x1="6" y1="6" x2="18" y2="18" />
      <line x1="18" y1="6" x2="6" y2="18" />
    </>
  ),
  plus: (
    <>
      <line x1="12" y1="5" x2="12" y2="19" />
      <line x1="5" y1="12" x2="19" y2="12" />
    </>
  ),
  sun: (
    <>
      <circle cx="12" cy="12" r="4.2" />
      <line x1="12" y1="2.5" x2="12" y2="5" />
      <line x1="12" y1="19" x2="12" y2="21.5" />
      <line x1="4.2" y1="4.2" x2="6" y2="6" />
      <line x1="18" y1="18" x2="19.8" y2="19.8" />
      <line x1="2.5" y1="12" x2="5" y2="12" />
      <line x1="19" y1="12" x2="21.5" y2="12" />
      <line x1="4.2" y1="19.8" x2="6" y2="18" />
      <line x1="18" y1="6" x2="19.8" y2="4.2" />
    </>
  ),
  moon: <path d="M20 14.5a8.5 8.5 0 1 1-9.5-13 7 7 0 0 0 9.5 13Z" />,
  menu: (
    <>
      <line x1="4" y1="7" x2="20" y2="7" />
      <line x1="4" y1="12" x2="20" y2="12" />
      <line x1="4" y1="17" x2="20" y2="17" />
    </>
  ),
  repost: (
    <>
      <polyline points="17 2 21 6 17 10" />
      <path d="M3 11V9a3 3 0 0 1 3-3h15" />
      <polyline points="7 22 3 18 7 14" />
      <path d="M21 13v2a3 3 0 0 1-3 3H3" />
    </>
  ),
  edit: (
    <>
      <path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17v3Z" />
      <line x1="13.5" y1="6.5" x2="17.5" y2="10.5" />
    </>
  ),
  trash: (
    <>
      <polyline points="4 7 20 7" />
      <path d="M9 7V4h6v3" />
      <path d="M6 7l1 13h10l1-13" />
    </>
  ),
  refresh: (
    <>
      <path d="M20 11a8 8 0 0 0-14.3-4.9L4 8" />
      <polyline points="4 3 4 8 9 8" />
      <path d="M4 13a8 8 0 0 0 14.3 4.9L20 16" />
      <polyline points="20 21 20 16 15 16" />
    </>
  ),
  external: (
    <>
      <path d="M14 4h6v6" />
      <line x1="20" y1="4" x2="11" y2="13" />
      <path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <line x1="16.5" y1="16.5" x2="21" y2="21" />
    </>
  ),
  image: (
    <>
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <circle cx="8.5" cy="9.5" r="1.8" />
      <polyline points="21 16 15.5 10.5 5 20" />
    </>
  ),
  video: (
    <>
      <rect x="3" y="6" width="13" height="12" rx="2" />
      <polygon points="21 8 16 12 21 16 21 8" />
    </>
  ),
  comment: <path d="M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-8l-5 4v-4H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" />,
  sparkles: (
    <>
      <path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8Z" />
      <path d="M19 15l.8 2.2 2.2.8-2.2.8L19 21l-.8-2.2L16 18l2.2-.8Z" />
    </>
  ),
  pixels: (
    <>
      <rect x="3" y="3" width="18" height="18" rx="2" />
      <rect x="7" y="7" width="3" height="3" fill="currentColor" stroke="none" />
      <rect x="14" y="10" width="3" height="3" fill="currentColor" stroke="none" />
      <rect x="9" y="15" width="3" height="3" fill="currentColor" stroke="none" />
    </>
  ),
  send: (
    <>
      <line x1="21" y1="3" x2="10" y2="14" />
      <polygon points="21 3 14 21 10 14 3 10 21 3" />
    </>
  ),
  alert: (
    <>
      <path d="M12 3 2 20h20Z" />
      <line x1="12" y1="10" x2="12" y2="14" />
      <circle cx="12" cy="17" r=".6" fill="currentColor" />
    </>
  ),
  dollar: (
    <>
      <line x1="12" y1="2" x2="12" y2="22" />
      <path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
    </>
  ),
  facebook: <path d="M14 8h3V4h-3a4 4 0 0 0-4 4v2H7v4h3v8h4v-8h3l1-4h-4V8Z" />,
};

export type IconName = keyof typeof paths;

export default function Icon({ name, size, ...rest }: SVGProps<SVGSVGElement> & { name: IconName; size?: number }) {
  return (
    <Svg size={size} {...rest}>
      {paths[name]}
    </Svg>
  );
}
