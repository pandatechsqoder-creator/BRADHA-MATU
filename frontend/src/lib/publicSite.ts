const configured = import.meta.env.VITE_PUBLIC_SITE_URL as string | undefined

export function publicSiteUrl(path = '/'): string {
  if (configured) {
    return new URL(path.replace(/^\//, ''), configured.endsWith('/') ? configured : `${configured}/`).toString()
  }

  return path
}
