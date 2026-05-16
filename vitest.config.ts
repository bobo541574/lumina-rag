import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['resources/js/**/*.{test,spec}.{ts,js}'],
  },
  resolve: {
    alias: {
      '@': '/resources/js',
    },
  },
})
