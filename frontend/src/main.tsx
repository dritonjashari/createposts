import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client';
import './styles/globals.css';
import './index.css';
import App from './App.tsx';
import { ROUTER_BASENAME } from './lib/appBase';
import { AuthProvider } from './context/AuthContext';
import { SharedMediaProvider } from './context/SharedMediaContext';
import { ThemeProvider } from './context/ThemeContext';
import { persistOptions, queryClient } from './queryClient';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <PersistQueryClientProvider client={queryClient} persistOptions={persistOptions}>
        <BrowserRouter basename={ROUTER_BASENAME}>
          <AuthProvider>
            <SharedMediaProvider>
              <App />
            </SharedMediaProvider>
          </AuthProvider>
        </BrowserRouter>
      </PersistQueryClientProvider>
    </ThemeProvider>
  </StrictMode>,
);
