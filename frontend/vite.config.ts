import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: { '@': path.resolve(import.meta.dirname, './src') },
  },
  build: {
    rolldownOptions: {
      output: {
        // Bibliothèques stables regroupées pour un cache navigateur durable ;
        // le reste est découpé par route (React.lazy).
        codeSplitting: {
          groups: [
            { name: 'react-vendor', test: /node_modules[\\/](react|react-dom|react-router|scheduler)[\\/]/ },
            { name: 'data-vendor', test: /node_modules[\\/](@tanstack|zod|react-hook-form|@hookform)[\\/]/ },
            // Icônes : un seul fichier plutôt qu'une requête par icône partagée entre pages.
            { name: 'icons', test: /node_modules[\\/]lucide-react[\\/]/ },
          ],
        },
      },
    },
  },
})
