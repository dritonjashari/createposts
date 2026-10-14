import { APP_BASE } from '../lib/appBase';

// The deployed app calls the backend that sits next to it in the repository
// (<app>/backend/api). VITE_API_BASE_URL overrides that: .env.development
// points the Vite dev server at XAMPP.
const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) || `${APP_BASE}backend/api`;

const TOKEN_STORAGE_KEY = 'cp_token';

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY);
}

export function setToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_STORAGE_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
  }
}

export class ApiError extends Error {
  status: number;
  body: unknown;
  constructor(status: number, message: string, body: unknown) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

// Fired on any 401 so the app can redirect to /login without every call site
// having to check for it individually.
type UnauthorizedListener = () => void;
let unauthorizedListener: UnauthorizedListener | null = null;
export function onUnauthorized(listener: UnauthorizedListener): void {
  unauthorizedListener = listener;
}

// Fired on any 403 — usually means an admin changed this user's role while
// they were logged in, so the app should re-check who they are.
let forbiddenListener: (() => void) | null = null;
export function onForbidden(listener: (() => void) | null): void {
  forbiddenListener = listener;
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  formData?: FormData;
  /** Abort and fail with an ApiError after this many ms (default: no limit). */
  timeoutMs?: number;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const token = getToken();
  const headers: Record<string, string> = {};
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  let body: BodyInit | undefined;
  if (options.formData) {
    body = options.formData; // browser sets the multipart Content-Type + boundary
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(options.body);
  }

  const controller = options.timeoutMs ? new AbortController() : null;
  const timer = controller ? setTimeout(() => controller.abort(), options.timeoutMs) : null;
  let res: Response;
  let data: unknown = null;
  let unreadable = false;
  try {
    res = await fetch(`${API_BASE_URL}/${path.replace(/^\//, '')}`, {
      method: options.method ?? 'GET',
      headers,
      body,
      signal: controller?.signal,
    });
    const isJson = res.headers.get('content-type')?.includes('application/json');
    if (isJson) {
      const text = await res.text();
      try {
        data = text === '' ? null : JSON.parse(text);
      } catch {
        // e.g. a PHP warning mixed into the body — never hand a page `null`
        // as if it were real data (it would sit on "Loading…" forever).
        unreadable = true;
      }
    }
  } catch (err) {
    if (controller?.signal.aborted) {
      throw new ApiError(0, 'The server took too long to respond. Please try again.', null);
    }
    throw err;
  } finally {
    if (timer) clearTimeout(timer);
  }

  if (res.status === 401) {
    unauthorizedListener?.();
  } else if (res.status === 403) {
    forbiddenListener?.();
  }

  if (res.ok && unreadable) {
    throw new ApiError(res.status, 'The server sent an invalid response. Please try again.', null);
  }

  if (!res.ok) {
    const message = (data && typeof data === 'object' && 'error' in data)
      ? String((data as { error: unknown }).error)
      : `Request failed (${res.status})`;
    throw new ApiError(res.status, message, data);
  }

  return data as T;
}
