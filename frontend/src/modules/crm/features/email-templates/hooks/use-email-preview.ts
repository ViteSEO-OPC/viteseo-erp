"use client";

import { useMemo, useState } from "react";
import { isAxiosError } from "axios";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useDebouncedValue } from "@/core/use-debounced-value";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { apiFieldErrors } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import {
  NO_PROBLEMS,
  documentProblems,
  documentRegions,
  serializeDocument,
} from "@/modules/crm/features/email-templates/lib/email-design";
import type {
  DesignBlock,
  DesignProblems,
  DesignRegion,
  EmailDesignLayout,
  EmailDesignPreview,
  TemplateContent,
} from "@/modules/crm/features/email-templates/types/email-design-types";

/** The two server previews: a template's content in a design, and a design with sample content. */
export type PreviewEndpoint = "/settings/email-templates/preview" | "/settings/email-designs/preview";

/** Long enough that typing settles into one request; each endpoint allows 60 a minute. */
const PREVIEW_DELAY_MS = 700;

/** Labels for the request fields other than the document, when the server refuses one of them. */
const OTHER_FIELD_LABELS: Record<string, string> = { subject: "Subject", email_design_id: "Email design" };

type PreviewResult =
  | { ok: true; preview: EmailDesignPreview }
  | { ok: false; errors: Record<string, string>; regions: Partial<Record<DesignRegion, readonly DesignBlock[]>> };

type Options = {
  endpoint: PreviewEndpoint;
  /** The request field the document travels in. */
  field: "layout" | "content";
  document: EmailDesignLayout | TemplateContent;
  /** Other request fields sent alongside, such as the subject and the design a template is shown in. */
  extra?: Record<string, string | null>;
};

/**
 * A designer's live preview: POST the document — as one JSON string — and any extra fields to a server
 * preview endpoint, once edits settle.
 *
 * The server is the only renderer, so this hook never builds HTML — it asks. A 422 is not a failure here: it
 * is the server saying what is wrong, and comes back as `problems`, placed on the blocks of the document that
 * was SENT. The last good rendering is kept so the preview does not go blank while a field is mid-edit.
 * Network and server errors surface as `requestFailed`, with `retry`.
 */
export function useEmailPreview({ endpoint, field, document, extra }: Options) {
  // The exact request body, as a string: the debounce and the cache key compare it by value.
  const body = useMemo(() => JSON.stringify({ ...extra, [field]: serializeDocument(document) }), [extra, field, document]);
  const settled = useDebouncedValue(body, PREVIEW_DELAY_MS);
  const [lastGood, setLastGood] = useState<EmailDesignPreview | null>(null);

  const query = useQuery({
    queryKey: queryKeys.emailTemplates.preview(endpoint, settled),
    queryFn: async ({ signal }): Promise<PreviewResult> => {
      const payload = JSON.parse(settled) as Record<string, unknown>;
      try {
        const res = await apiClient.post(endpoint, payload, { signal });
        const preview = res.data.data as EmailDesignPreview;
        setLastGood(preview);
        return { ok: true, preview };
      } catch (err) {
        if (isAxiosError(err) && err.response?.status === 422) {
          const sent = JSON.parse(payload[field] as string) as EmailDesignLayout | TemplateContent;
          return { ok: false, errors: apiFieldErrors(err), regions: documentRegions(sent) };
        }
        throw err;
      }
    },
    placeholderData: keepPreviousData,
    retry: false,
    staleTime: 5 * 60_000,
    gcTime: 60_000,
  });

  const result = query.data;

  const problems: DesignProblems = useMemo(() => {
    if (!result || result.ok) return NO_PROBLEMS;
    const placed = documentProblems(result.errors, field, result.regions);
    // A refused subject or design also stops the preview, so it is listed with the rest.
    const others = Object.entries(result.errors)
      .filter(([key]) => key.split(".")[0] !== field)
      .map(([key, message]) => `${OTHER_FIELD_LABELS[key] ?? key}: ${message}`);
    return others.length > 0 ? { ...placed, general: [...others, ...placed.general] } : placed;
  }, [result, field]);

  return {
    preview: result?.ok ? result.preview : lastGood,
    problems,
    isUpdating: query.isFetching || body !== settled,
    requestFailed: query.isError,
    retry: () => void query.refetch(),
  };
}
