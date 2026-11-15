/** @type {import('next').NextConfig} */
const configuredBasePath = process.env.NEXT_PUBLIC_BASE_PATH?.replace(/\/$/, '') || ''

const nextConfig = {
  // Static export: the whole app is client-side and talks to Firebase directly
  // (or to the browser's local demo database when Firebase is not configured).
  output: 'export',
  distDir: 'dist',
  trailingSlash: true,
  images: { unoptimized: true },
  ...(configuredBasePath
    ? { basePath: configuredBasePath, assetPrefix: `${configuredBasePath}/` }
    : {}),
}

export default nextConfig
