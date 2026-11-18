/// <reference types="vitest/config" />
import http from 'node:http'
import type { Plugin } from 'vite'
import { defineConfig, configDefaults } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const BACKEND_ORIGIN = 'http://127.0.0.1:8000'
const SPA_PREFIXES = [
  '/login',
  '/register',
  '/forgot-password',
  '/verify',
  '/onboarding',
  '/app',
  '/profile',
  '/settings',
  '/notifications',
  '/support',
  '/platform',
  '/business',
  '/invitations',
  '/workforce',
  '/pos',
]

const ASSET_PREFIXES = ['/@', '/src/', '/node_modules/', '/__', '/api']

function servesSpa(pathname: string): boolean {
  if (ASSET_PREFIXES.some((prefix) => pathname.startsWith(prefix))) {
    return true
  }

  return SPA_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))
}

// Public-site routes are Blade pages; everything outside the staff app is proxied to Laravel.
function publicSiteProxy(): Plugin {
  return {
    name: 'brathamatu-public-site-proxy',
    apply: 'serve',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const pathname = new URL(req.url ?? '/', BACKEND_ORIGIN).pathname
        if (servesSpa(pathname)) {
          next()
          return
        }

        const target = new URL(req.url ?? '/', BACKEND_ORIGIN)
        const proxyRequest = http.request(
          {
            hostname: target.hostname,
            port: target.port || 80,
            path: `${target.pathname}${target.search}`,
            method: req.method,
            headers: req.headers,
          },
          (proxyResponse) => {
            res.writeHead(proxyResponse.statusCode ?? 502, proxyResponse.headers)
            proxyResponse.pipe(res)
          },
        )

        proxyRequest.on('error', () => {
          res.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' })
          res.end('The Laravel backend is not running on 127.0.0.1:8000.')
        })

        req.pipe(proxyRequest)
      })
    },
  }
}

export default defineConfig({
  plugins: [publicSiteProxy(), react(), tailwindcss()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    exclude: [...configDefaults.exclude, 'e2e/**', 'e2e-public/**'],
  },
})