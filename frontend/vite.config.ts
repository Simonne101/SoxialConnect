import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Tunnel HTTPS de développement (docs/publishing.md §12) : nom d'hôte seul (ex. xxx.ngrok-free.dev),
  // lu dans frontend/.env.local (non versionné). Ce n'est pas un secret ; aucun secret ne doit
  // jamais figurer dans une variable VITE_. Sans valeur : seul localhost est accepté (comportement Vite).
  const env = loadEnv(mode, import.meta.dirname, '')
  const rawTunnelHost = (env.VITE_DEV_TUNNEL_HOST || env.DEV_TUNNEL_HOST || '').trim().toLowerCase()
  const tunnelHost = /^[a-z0-9.-]+\.[a-z]{2,}$/.test(rawTunnelHost) ? rawTunnelHost : ''

  return {
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: { '@': path.resolve(import.meta.dirname, './src') },
    },
    // Développement : l'API Laravel est servie via le même port que le site (VITE_API_URL=same-origin).
    // Le navigateur ne parle qu'à localhost:5173 : pas de CORS, pas de second port qu'un antivirus
    // ou une extension pourrait bloquer. L'en-tête Host est conservé (URLs signées des médias).
    server: {
      // Uniquement le domaine du tunnel, en plus de localhost (jamais « true » ni tous les hôtes).
      // HMR inchangé : sur localhost il fonctionne comme avant ; via le tunnel, le client Vite
      // se rabat sur ws://localhost:5173 (même machine).
      ...(tunnelHost ? { allowedHosts: [tunnelHost] } : {}),
      proxy: {
        '/api': { target: 'http://localhost:8000', changeOrigin: false },
        '/sanctum': { target: 'http://localhost:8000', changeOrigin: false },
        // Retours OAuth (/oauth/youtube/callback, /oauth/tiktok/callback) : même origine que la SPA, cookie de session inclus.
        '/oauth': { target: 'http://localhost:8000', changeOrigin: false },
        // Lien de vérification de l'adresse e-mail (route web Laravel signée, cookie de session du site).
        '/email/verify': { target: 'http://localhost:8000', changeOrigin: false },
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
  }
})
