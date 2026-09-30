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
  // Développement : l'API Laravel est servie via le même port que le site (VITE_API_URL=same-origin).
  // Le navigateur ne parle qu'à localhost:5173 : pas de CORS, pas de second port qu'un antivirus
  // ou une extension pourrait bloquer. L'en-tête Host est conservé (URLs signées des médias).
  server: {
    proxy: {
      '/api': { target: 'http://localhost:8000', changeOrigin: false },
      '/sanctum': { target: 'http://localhost:8000', changeOrigin: false },
    },
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
