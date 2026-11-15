// Copies the PHP API next to the static export so `dist/` can be uploaded as-is.
import { cpSync, existsSync, mkdirSync } from 'node:fs'

const out = 'dist'
cpSync('api', `${out}/api`, {
  recursive: true,
  // Never ship local test data; the server creates its own database on first run.
  filter: (src) => !/[\\/]data[\\/].+\.(json|php|lock|tmp)$/.test(src),
})
if (!existsSync(`${out}/uploads`)) mkdirSync(`${out}/uploads`, { recursive: true })
console.log('✓ PHP API copied to dist/api')
