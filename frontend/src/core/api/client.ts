/**
 * Projects-module API client shim.
 *
 * The PMS module was written against `@/core/api/client`, which used to hardcode
 * the backend host at `:8000`. In the unified platform there is ONE api client
 * (`@/core/api-client`) whose base URL comes from NEXT_PUBLIC_API_BASE_URL
 * (default `/api/v1`) and which attaches the shared Sanctum token + handles 401.
 *
 * We simply re-export it so every Projects request flows through the single
 * shared client. Module code therefore uses paths RELATIVE to `/api/v1`
 * (e.g. `/projects/workspaces`, `/projects/users`).
 */
import { apiClient } from "@/core/api-client";

export { apiClient };
export default apiClient;
