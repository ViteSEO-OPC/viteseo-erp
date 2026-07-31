import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

/**
 * Realtime ownership boundary, enforced rather than documented.
 *
 * `src/core/realtime/realtime-manager.ts` is the ONE owner of the Echo singleton, the
 * connection and every channel subscription. Before it existed, feature modules held
 * shared channels themselves, and `echo.leave()` / `teardownEcho()` from one module
 * could silently remove another module's listeners on `user.{id}` — an intermittent
 * race decided by React effect ordering.
 *
 * Comments do not stop that coming back; this does. Features import
 * `useRealtimeEvent` / `useRealtimeReconnect` and register callbacks. Anything
 * reaching for the socket itself now fails lint at the import.
 *
 * `getSocketId` is deliberately NOT restricted — the axios client needs it for the
 * `X-Socket-Id` header, and reading the id owns nothing.
 */
const realtimeOwnershipBoundary = {
  name: "realtime-ownership-boundary",
  files: ["src/**/*.ts", "src/**/*.tsx"],
  ignores: ["src/core/realtime/**"],
  rules: {
    "no-restricted-imports": [
      "error",
      {
        paths: [
          {
            name: "@/core/realtime/echo",
            importNames: ["getEcho", "teardownEcho"],
            message:
              "The Echo instance is owned by src/core/realtime/realtime-manager.ts. Use useRealtimeEvent() / useRealtimeReconnect() from @/core/realtime/use-realtime — calling echo.leave() or teardownEcho() from a feature removes listeners other modules still depend on.",
          },
          {
            name: "@/core/realtime/realtime-manager",
            importNames: ["acquireRealtime", "releaseRealtime", "subscribe", "onReconnect"],
            message:
              "The realtime connection lifecycle belongs to <RealtimeProvider>. Features register callbacks with useRealtimeEvent() / useRealtimeReconnect() from @/core/realtime/use-realtime.",
          },
        ],
      },
    ],
  },
};

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  realtimeOwnershipBoundary,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
