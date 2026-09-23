"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
    ChannelFilter,
    InboxConversation,
    InboxConversationDetail,
} from "@/modules/crm/features/inbox/types/inbox-types";

type ListResponse = {
    data: InboxConversation[];
    meta?: { total?: number; per_page?: number; current_page?: number; last_page?: number };
};

/**
 * The conversation list.
 *
 * The channel filter is sent as a query parameter and is also part of the query key, so switching
 * channels is a separate cached list rather than a refetch that briefly shows the wrong rows.
 * `all` is the ABSENCE of the parameter — the API validates `channel` against an allowlist, so
 * sending a synthetic "all" would be a 422.
 */
export function useConversations(channel: ChannelFilter) {
    return useQuery({
        queryKey: queryKeys.conversations.list({ channel }),
        queryFn: async (): Promise<ListResponse> => {
            const params = channel === "all" ? {} : { channel };
            const { data } = await apiClient.get<ListResponse>("/conversations", { params });
            return data;
        },
    });
}

/** One conversation and its thread. Not fetched until a row is actually selected. */
export function useConversation(id: string | null) {
    return useQuery({
        queryKey: queryKeys.conversations.detail(id ?? "none"),
        enabled: id !== null,
        queryFn: async (): Promise<InboxConversationDetail> => {
            const { data } = await apiClient.get<{ data: InboxConversationDetail }>(`/conversations/${id}`);
            return data.data;
        },
    });
}
