"use client";

import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Search, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import { Checkbox } from "@/modules/crm/components/ui/checkbox";
import { cn } from "@/modules/crm/lib/utils";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { colorForNiche } from "@/modules/crm/features/leads/config/niche-colors";
import { useAssignLeadsToNiche } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The niche created in Sequence 1 — already REGISTERED in crm_niches, so it is a valid assign
   *  target. Assignment sets each selected lead's `industry` to this exact (canonical) name. */
  niche: string;
};

/**
 * Sequence 2 — Assign Leads (optional). Single responsibility: move existing leads onto the niche
 * just created. SEARCH-FIRST: nothing is fetched or rendered until the user types (server-side
 * search over the existing GET /leads endpoint — no preloaded list). The result list is a fixed
 * height (~5 rows) and scrolls; the dialog height never grows with the results.
 */
export function AssignLeadsModal({ open, onOpenChange, niche }: Props) {
  const assignMutation = useAssignLeadsToNiche();
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [selectedLeadIds, setSelectedLeadIds] = useState<Set<string>>(new Set());

  // No reset effect needed: the parent renders this keyed by the niche name, so each niche gets a
  // fresh component instance (empty search + selection) on mount.
  // Debounce the search so a request fires per pause, not per keystroke.
  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const hasQuery = debounced.length > 0;
  // Read-only, server-side search — reuses the existing GET /leads endpoint; only runs once the user
  // has typed. No mutation, no new API.
  const leadsQuery = useQuery({
    queryKey: [...queryKeys.leads.lists(), "niche-assign-search", debounced],
    queryFn: () =>
      apiClient
        .get("/leads", { params: { search: debounced, per_page: 20 } })
        .then((r) => r.data.data as Lead[]),
    enabled: open && hasQuery,
  });
  const results = leadsQuery.data ?? [];

  const toggleLead = (id: string) =>
    setSelectedLeadIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const canAssign = selectedLeadIds.size > 0 && !assignMutation.isPending;
  const handleAssign = () => {
    if (!canAssign) return;
    // `niche` is the just-created (registered) name, so leads.industry can only become a registered
    // crm_niches.name — the canonical rule holds.
    assignMutation.mutate(
      { ids: [...selectedLeadIds], niche },
      {
        onSuccess: ({ succeeded }) => {
          setSelectedLeadIds((prev) => {
            const next = new Set(prev);
            succeeded.forEach((id) => next.delete(id));
            return next;
          });
        },
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onOpenChange(false); }}>
      <DialogContent
        showCloseButton={false}
        /* z-[60] on content + backdrop so this stacks above the Create/Edit-Lead form dialog. */
        className="z-[60] flex h-[520px] max-h-[85dvh] w-full flex-col gap-0 overflow-hidden rounded-2xl p-0 shadow-xl sm:max-w-[520px]"
        overlayClassName="z-[60]"
      >
        {/* FIXED header */}
        <DialogHeader className="shrink-0 px-6 pt-6 pb-0">
          <div className="flex items-start justify-between gap-4">
            <div className="flex flex-col gap-1">
              <DialogTitle className="text-xl font-bold text-card-foreground">
                Assign leads to this niche
              </DialogTitle>
              <DialogDescription>
                Optional — move existing leads into the new niche. Assigning a lead replaces its
                current niche.
              </DialogDescription>
            </div>
            <button type="button" onClick={() => onOpenChange(false)} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        {/* FIXED search */}
        <div className="shrink-0 px-6 pt-5">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search leads…"
              aria-label="Search leads"
              className="h-10 w-full pl-9 pr-3.5"
              autoFocus
            />
          </div>
        </div>

        {/* Results — fixed-height, scrollable region (~5 rows). Search-first: nothing until typing. */}
        <div className="min-h-0 flex-1 overflow-y-auto px-6 pt-3 pb-4">
          {!hasQuery ? (
            <div className="flex h-full items-center justify-center">
              <p className="text-sm text-muted-foreground">Start typing to search leads.</p>
            </div>
          ) : leadsQuery.isLoading ? (
            <div className="flex h-full items-center justify-center">
              <p className="text-sm text-muted-foreground">Searching&hellip;</p>
            </div>
          ) : results.length === 0 ? (
            <div className="flex h-full items-center justify-center">
              <p className="text-sm text-muted-foreground">No leads found for &ldquo;{debounced}&rdquo;.</p>
            </div>
          ) : (
            <ul className="flex flex-col gap-2">
              {results.map((lead) => {
                const checked = selectedLeadIds.has(lead.id);
                return (
                  <li
                    key={lead.id}
                    className={cn(
                      "flex items-center gap-3 rounded-lg border px-3 py-2 transition-colors",
                      checked
                        ? "border-teal-400 bg-muted/40"
                        : "border-border bg-background hover:bg-muted/40",
                    )}
                  >
                    <Checkbox
                      aria-label={`Select ${lead.business}`}
                      checked={checked}
                      onCheckedChange={() => toggleLead(lead.id)}
                    />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium text-foreground">{lead.business}</p>
                      {lead.industry ? (
                        <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                          <span
                            className="h-2 w-2 shrink-0 rounded-full"
                            style={{ backgroundColor: colorForNiche(lead.industry) }}
                            aria-hidden
                          />
                          {lead.industry}
                        </span>
                      ) : (
                        <span className="mt-0.5 block text-xs text-muted-foreground">No niche</span>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>

        {/* FIXED footer: selection count + Assign */}
        <DialogFooter className="mx-0 mb-0 shrink-0 flex-row items-center justify-between gap-3 border-t border-border-subtle bg-transparent px-6 py-4">
          <span className="text-sm text-muted-foreground">{selectedLeadIds.size} selected</span>
          <Button
            type="button"
            size="sm"
            className="bg-teal-100 text-teal-700 hover:bg-teal-200 disabled:opacity-50 dark:bg-teal-400/15 dark:text-teal-300 dark:hover:bg-teal-400/25"
            disabled={!canAssign}
            onClick={handleAssign}
          >
            {assignMutation.isPending ? "Assigning…" : "Assign to niche"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
