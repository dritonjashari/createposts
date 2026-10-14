import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';

interface SharedMediaValue {
  sharedFile: File | null;
  setSharedFile: (file: File | null) => void;
}

const SharedMediaContext = createContext<SharedMediaValue | null>(null);

export function SharedMediaProvider({ children }: { children: ReactNode }) {
  const [sharedFile, setSharedFileState] = useState<File | null>(null);

  const setSharedFile = useCallback((file: File | null) => {
    setSharedFileState(file);
  }, []);

  return (
    <SharedMediaContext.Provider value={{ sharedFile, setSharedFile }}>
      {children}
    </SharedMediaContext.Provider>
  );
}

export function useSharedMedia() {
  const ctx = useContext(SharedMediaContext);
  if (!ctx) throw new Error('useSharedMedia must be used within SharedMediaProvider');
  return ctx;
}
