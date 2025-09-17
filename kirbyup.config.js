import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'kirbyup/config'
import { nodePolyfills } from 'vite-plugin-node-polyfills'

const currentDir = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig({
  vite: {
    resolve: {
      alias: {
        '@/': `${resolve(currentDir, 'tests/kirby/panel/src')}/`,
      },
    },
    plugins: [
      nodePolyfills()
    ],
  },
})
