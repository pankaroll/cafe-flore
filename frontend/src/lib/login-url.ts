// Move to the configured address before showing the PIN form. API requests and
// the Secure session cookie then use the same origin on every device.
export function loginRedirect(publicUrl: string | undefined, currentUrl: string): string | null {
  if (!publicUrl) return null;
  const target = new URL(publicUrl);
  const current = new URL(currentUrl);
  if (!['http:', 'https:'].includes(target.protocol)) throw new Error('Nieprawidłowy adres aplikacji w konfiguracji.');
  if (target.origin === current.origin) return null;
  target.pathname = target.pathname.replace(/\/?$/, '/');
  return target.href;
}
