import { apiClient } from "@/core/api-client";

import type {
  KioskAccessCredentials,
  KioskAccessGrant,
  KioskCatalog,
  KioskSelection,
  KioskSelectionInput,
} from "./types";

const BASE = "/kiosk";

export const kioskApi = {
  /** The configurator option catalog (business/website types, sections, brand kits). */
  getCatalog: async (): Promise<KioskCatalog> => (await apiClient.get(`${BASE}/catalog`)).data.data,

  /** Save a website selection (a captured lead). */
  createSelection: async (payload: KioskSelectionInput): Promise<KioskSelection> =>
    (await apiClient.post(`${BASE}/selections`, payload)).data.data,

  /** Captured leads for the current org. */
  listSelections: async (): Promise<KioskSelection[]> =>
    (await apiClient.get(`${BASE}/selections`)).data.data,

  deleteSelection: (id: number) => apiClient.delete(`${BASE}/selections/${id}`),

  /** Temp client access (1-day demo logins). */
  listAccess: async (): Promise<KioskAccessGrant[]> => (await apiClient.get(`${BASE}/access`)).data.data,
  createAccess: async (label: string): Promise<KioskAccessCredentials> =>
    (await apiClient.post(`${BASE}/access`, { label })).data.data,
  revokeAccess: (id: number) => apiClient.delete(`${BASE}/access/${id}`),
};
