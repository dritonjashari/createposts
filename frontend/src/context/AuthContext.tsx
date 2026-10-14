import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { apiRequest, getToken, setToken, onUnauthorized, onForbidden, ApiError } from '../api/client';
import { clearQueryCache } from '../queryClient';

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'editor';
}

interface AuthContextValue {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string, confirmPassword: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

// auth/me is a single cheap DB lookup — if it hasn't answered by now the
// backend is stuck, and showing the login page beats a blank screen forever.
const AUTH_TIMEOUT_MS = 15000;
// How often a logged-in tab re-checks its own account, so an admin's role
// change (or delete) reaches an already-open session without a re-login.
const USER_REFRESH_MS = 60000;

// The last known user is kept next to the token, so a reload renders the app
// immediately instead of waiting on auth/me; auth/me still runs in the
// background and corrects (or logs out) the session if anything changed.
const USER_STORAGE_KEY = 'cp_user';

function loadCachedUser(): User | null {
  try {
    if (!getToken()) {
      return null;
    }
    const u = JSON.parse(localStorage.getItem(USER_STORAGE_KEY) ?? 'null') as User | null;
    return u && typeof u.id === 'number' && (u.role === 'admin' || u.role === 'editor') ? u : null;
  } catch {
    return null;
  }
}

function saveCachedUser(u: User | null): void {
  try {
    if (u) {
      localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(u));
    } else {
      localStorage.removeItem(USER_STORAGE_KEY);
    }
  } catch {
    // storage unavailable — just no instant start next time
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(loadCachedUser);
  const [loading, setLoading] = useState(() => user === null && getToken() !== null);
  const queryClient = useQueryClient();
  const userRef = useRef<User | null>(user);
  const refreshing = useRef(false);

  const applyUser = useCallback(
    (next: User | null) => {
      const prev = userRef.current;
      userRef.current = next;
      setUser(next);
      saveCachedUser(next);
      if (prev && next && prev.id !== next.id) {
        // Token now belongs to someone else: never show the old user's data.
        clearQueryCache();
      }
      // Role changed under us: everything cached was fetched with the old
      // privileges (admin-shaped dashboard, is_admin flags, ...) — drop it
      // and refetch whatever is on screen with the new role.
      if (prev && next && prev.id === next.id && prev.role !== next.role) {
        queryClient.resetQueries();
      }
    },
    [queryClient],
  );

  const refreshUser = useCallback(async () => {
    if (!getToken() || refreshing.current) {
      return;
    }
    refreshing.current = true;
    try {
      const res = await apiRequest<{ user: User }>('auth/me', { timeoutMs: AUTH_TIMEOUT_MS });
      applyUser(res.user);
    } catch {
      // 401 is handled by onUnauthorized; a transient network error just
      // waits for the next refresh.
    } finally {
      refreshing.current = false;
    }
  }, [applyUser]);

  useEffect(() => {
    onUnauthorized(() => {
      setToken(null);
      userRef.current = null;
      setUser(null);
      saveCachedUser(null);
      // A session can be invalidated without an explicit logout() call (token
      // expired, revoked, account deleted, etc.) — wipe cached query data all
      // the same, so the next person to use this browser tab never sees a
      // stale response meant for the previous user.
      clearQueryCache();
    });
    onForbidden(() => {
      void refreshUser();
    });
    return () => onForbidden(null);
  }, [queryClient, refreshUser]);

  useEffect(() => {
    if (!getToken()) {
      saveCachedUser(null);
      setLoading(false);
      return;
    }
    apiRequest<{ user: User }>('auth/me', { timeoutMs: AUTH_TIMEOUT_MS })
      .then((res) => applyUser(res.user))
      .catch((err) => {
        // Only a real 401 means the token is dead (onUnauthorized already
        // cleared it). Keep the token on a timeout/network blip so a reload
        // can still restore the session.
        if (err instanceof ApiError && err.status === 401) {
          setToken(null);
          saveCachedUser(null);
        }
      })
      .finally(() => setLoading(false));
  }, [applyUser]);

  useEffect(() => {
    if (!user) {
      return;
    }
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        void refreshUser();
      }
    };
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);
    const timer = window.setInterval(() => void refreshUser(), USER_REFRESH_MS);
    return () => {
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
      window.clearInterval(timer);
    };
  }, [user, refreshUser]);

  async function login(email: string, password: string) {
    // Wipe any cache left behind by a previous session in this same browser
    // tab BEFORE the new user's data starts loading, so a stale response
    // meant for someone else can never render for the new login.
    clearQueryCache();
    // Never send a leftover token with the login request — the backend would
    // reuse that old session id instead of issuing a fresh one.
    setToken(null);
    const res = await apiRequest<{ token: string; user: User }>('auth/login', {
      method: 'POST',
      body: { email, password },
      timeoutMs: 30000,
    });
    setToken(res.token);
    userRef.current = res.user;
    setUser(res.user);
    saveCachedUser(res.user);
  }

  async function register(name: string, email: string, password: string, confirmPassword: string) {
    await apiRequest('auth/register', {
      method: 'POST',
      body: { name, email, password, confirm_password: confirmPassword },
    });
  }

  async function logout() {
    try {
      await apiRequest('auth/logout', { method: 'POST', timeoutMs: AUTH_TIMEOUT_MS });
    } catch (err) {
      if (!(err instanceof ApiError)) {
        throw err;
      }
    } finally {
      setToken(null);
      userRef.current = null;
      setUser(null);
      saveCachedUser(null);
      clearQueryCache(); // don't let this user's cached data linger for whoever logs in next
    }
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return ctx;
}
