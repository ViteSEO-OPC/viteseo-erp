import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getToken } from '@/core/tokens';

/**
 * Lazily-constructed, SSR-safe Laravel Echo singleton talking to Laravel Reverb
 * (Pusher protocol). Private channels are authorized through the API's
 * /broadcasting/auth endpoint carrying the Sanctum Bearer token. We use `fetch`
 * (not apiClient) here to avoid an import cycle with the axios client, which
 * itself reads this module's socket id for the `X-Socket-Id` header.
 */
let echo: Echo<'reverb'> | null = null;

/**
 * The API origin for /broadcasting/auth: from NEXT_PUBLIC_API_BASE_URL in real
 * deployments (strip the /api/vN path), falling back to the :8000 dev-server quirk.
 */
function apiOrigin(): string {
  const base = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (base) return new URL(base).origin;
  return `${window.location.protocol}//${window.location.hostname}:8000`;
}

export function getEcho(): Echo<'reverb'> | null {
  if (typeof window === 'undefined') return null; // no sockets during SSR
  // Reverb is optional at this stage of the merge: with no app key configured
  // we skip real-time entirely rather than spamming failed WS connections.
  // Set NEXT_PUBLIC_REVERB_APP_KEY (+ run a Reverb server) to enable it.
  if (!process.env.NEXT_PUBLIC_REVERB_APP_KEY) return null;
  if (echo) return echo;

  // laravel-echo's reverb broadcaster expects Pusher on the global.
  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

  echo = new Echo({
    broadcaster: 'reverb',
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
    // Reverb runs on the same host as the API (mirrors the client.ts quirk).
    wsHost: window.location.hostname,
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080),
    forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (
          error: Error | null,
          data: { auth: string; channel_data?: string } | null,
        ) => void,
      ) => {
        fetch(`${apiOrigin()}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            Authorization: `Bearer ${getToken() ?? ''}`,
          },
          body: JSON.stringify({
            socket_id: socketId,
            channel_name: channel.name,
          }),
        })
          .then(async (res) => {
            if (!res.ok) throw new Error(`auth failed: ${res.status}`);
            callback(null, (await res.json()) as { auth: string });
          })
          .catch((err: Error) => callback(err, null));
      },
    }),
  });

  return echo;
}

/** The current connection's socket id (for the `X-Socket-Id` REST header). */
export function getSocketId(): string | undefined {
  return echo?.socketId();
}

/** Disconnect and drop the singleton (on logout / auth loss). */
export function teardownEcho(): void {
  if (echo) {
    echo.disconnect();
    echo = null;
  }
}
