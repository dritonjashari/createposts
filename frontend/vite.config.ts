import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
    plugins: [react()],
    // Relative asset URLs in the build, so one build works wherever the
    // repository is served from (domain root, /createposts/, ...): index.php
    // in the repository root injects the real path as <base href>.
    base: command === 'build' ? './' : '/',
}))
