// URL path the app is served from, with a trailing slash: "/" at a domain
// root, "/createposts/" in a subfolder. In the deployed build, index.php (in
// the repository root) injects it as <base href>; the Vite dev server has no
// <base> and serves the app at "/".
const baseElement = document.querySelector('base');

export const APP_BASE: string = baseElement ? new URL(baseElement.href).pathname : '/';

/** Router basename: APP_BASE without its trailing slash ("/" stays "/"). */
export const ROUTER_BASENAME: string = APP_BASE.replace(/\/+$/, '') || '/';
