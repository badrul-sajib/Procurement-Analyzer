/// <reference types="vitest/config" />
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/js/main.tsx'], refresh: true }),
    react(),
    tailwindcss(),
  ],
  resolve: {
    alias: { '@': path.resolve(import.meta.dirname, './resources/js') },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['resources/js/**/*.test.{ts,tsx}'],
    setupFiles: ['./resources/js/test/setup.ts'],
    css: false,
  },
})
