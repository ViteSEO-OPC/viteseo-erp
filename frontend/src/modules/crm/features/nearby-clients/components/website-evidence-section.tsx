"use client";

import { useState } from "react";
import { ChevronDown, ChevronRight, Search, ShieldCheck, Loader2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { cn } from "@/modules/crm/lib/utils";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import {
  useConfirmWebsiteAbsence,
  useWebsiteEvidence,
} from "@/modules/crm/features/nearby-clients/hooks/use-website-evidence";
import type {
  WebsiteCandidate,
  WebsiteEvidence,
} from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/**
 * Website search evidence — the trail behind "this business has no website", and the BD action that
 * concludes it.
 *
 * WHY THIS SCREEN EXISTS. `website-unknown` and `no-website` look similar on a card and mean opposite
 * things: one is "we have not looked", the other is "we looked and there is nothing" — the strongest
 * signal in the prospect pool. Nothing in the pipeline will bridge that gap on its own, deliberately,
 * because a search that finds nothing is not proof. A person reads what was tried and decides, and
 * this is where they read it.
 *
 * IT SHOWS WHAT WAS REFUSED, NOT JUST WHAT WAS FOUND. A rejected candidate is the useful evidence —
 * "we found mercurydrug.com and it was not this branch" is a much better basis for a decision than an
 * empty list. Refusals are therefore listed with their reason, not hidden.
 */
const SOURCE_LABEL: Record<string, string> = {
  osm_tag: "Map data",
  wikidata: "Brand registry",
  search: "Web search",
  ai: "AI suggestion",
  manual: "Entered by hand",
};

const REJECTION_LABEL: Record<string, string> = {
  unreachable: "did not respond",
  identity_mismatch: "belongs to a different business",
  insufficient_margin: "too close to another candidate to call",
  invalid_url: "not a usable address",
};

export function WebsiteEvidenceSection({ leadId, business }: { leadId: string; business: string }) {
  const [open, setOpen] = useState(false);
  const { can } = usePermissions();
  const { data, isLoading } = useWebsiteEvidence(leadId, open);

  return (
    <div className="rounded-lg border border-border-subtle" onClick={(e) => e.stopPropagation()}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full items-center gap-1.5 rounded-lg px-2.5 py-2 text-left text-[11px] font-semibold text-muted-foreground transition-colors hover:bg-muted/50"
      >
        {open ? (
          <ChevronDown className="h-3 w-3 shrink-0" aria-hidden />
        ) : (
          <ChevronRight className="h-3 w-3 shrink-0" aria-hidden />
        )}
        <Search className="h-3 w-3 shrink-0" aria-hidden />
        Website search evidence
      </button>

      {open && (
        <div className="border-t border-border-subtle px-2.5 py-2.5">
          {isLoading ? (
            <p className="text-[11px] text-muted-foreground">Loading evidence…</p>
          ) : data ? (
            <EvidenceBody
              evidence={data}
              business={business}
              canConfirm={can("crm.leads.edit")}
            />
          ) : (
            <p className="text-[11px] text-muted-foreground">Evidence could not be loaded.</p>
          )}
        </div>
      )}
    </div>
  );
}

function EvidenceBody({
  evidence,
  business,
  canConfirm,
}: {
  evidence: WebsiteEvidence;
  business: string;
  canConfirm: boolean;
}) {
  const { search, candidates } = evidence;

  if (!search.searched) {
    // Never searched is NOT the same as searched-and-empty, and offering a confirmation here would
    // invite exactly the unfounded guess this whole chain exists to prevent.
    return (
      <p className="text-[11px] leading-relaxed text-muted-foreground">
        No website search has run for this business yet. Until one does, its website is unknown rather
        than absent — that is why the priority stays neutral.
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-2.5">
      <p className="text-[11px] leading-relaxed text-muted-foreground">
        {search.candidatesFound === 0
          ? "The search ran and turned up no candidate address at all."
          : `${search.candidatesFound} candidate${search.candidatesFound === 1 ? "" : "s"} found, ${search.candidatesRejected} refused.`}
      </p>

      {candidates.length > 0 && (
        <ul className="flex flex-col gap-1.5">
          {candidates.map((c) => (
            <CandidateRow key={c.id} candidate={c} />
          ))}
        </ul>
      )}

      {search.confirmedAbsent ? (
        <div className="rounded-md border border-border-subtle bg-muted/40 p-2">
          <p className="flex items-center gap-1 text-[11px] font-semibold text-card-foreground">
            <ShieldCheck className="h-3 w-3 text-teal-600" aria-hidden />
            Confirmed to have no website
          </p>
          <p className="mt-0.5 text-[10px] text-muted-foreground">
            {[search.confirmedBy && `by ${search.confirmedBy}`, formatDate(search.confirmedAt)]
              .filter(Boolean)
              .join(" · ")}
          </p>
          {search.note && <p className="mt-1 text-[10px] italic text-muted-foreground">“{search.note}”</p>}
        </div>
      ) : search.canConfirmAbsence ? (
        canConfirm ? (
          <ConfirmAbsenceAction leadId={evidence.leadId} business={business} search={search} />
        ) : (
          <p className="text-[10px] text-muted-foreground">
            The search is exhausted. A colleague with lead-edit access can confirm this business has no
            website.
          </p>
        )
      ) : (
        <p className="text-[10px] leading-relaxed text-muted-foreground">
          {candidates.some((c) => c.verified)
            ? "A website was confirmed for this business, so there is nothing to confirm absent."
            : "The search is not finished — every candidate has to be checked before absence can be confirmed."}
        </p>
      )}
    </div>
  );
}

function CandidateRow({ candidate }: { candidate: WebsiteCandidate }) {
  const source = SOURCE_LABEL[candidate.source] ?? candidate.source;
  const verdict =
    candidate.verified === true
      ? "confirmed as this business"
      : candidate.verified === false
        ? (REJECTION_LABEL[candidate.rejectionReason ?? ""] ?? "refused")
        : "not checked yet";

  return (
    <li className="flex flex-col gap-0.5 rounded-md border border-border-subtle bg-muted/30 px-2 py-1.5">
      <span className="truncate text-[11px] font-medium text-card-foreground">{candidate.url}</span>
      <span className="text-[10px] text-muted-foreground">
        {source} ·{" "}
        <span className={cn(candidate.verified === true ? "text-green-600 dark:text-green-400" : undefined)}>
          {verdict}
        </span>
      </span>
    </li>
  );
}

function ConfirmAbsenceAction({
  leadId,
  business,
  search,
}: {
  leadId: string;
  business: string;
  search: WebsiteEvidence["search"];
}) {
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState("");
  const confirm = useConfirmWebsiteAbsence(leadId);

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => setOpen(true)}
      >
        <ShieldCheck className="h-3.5 w-3.5" aria-hidden />
        Confirm: no website
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Confirm {business} has no website</DialogTitle>
            <DialogDescription>
              {/* Said plainly, because it is a judgement the person is making, not a lookup the system
                  already did. Every automated tier came back empty; that is evidence, not proof. */}
              {search.candidatesFound === 0
                ? "The automated search found no candidate address for this business."
                : `All ${search.candidatesFound} candidate address${search.candidatesFound === 1 ? "" : "es"} were checked and refused.`}{" "}
              Confirming records this business as having no website, which makes it a high-priority
              prospect. Only confirm if you are satisfied none exists.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-1.5">
            <label htmlFor={`absence-note-${leadId}`} className="text-xs font-medium text-card-foreground">
              How did you check? <span className="text-muted-foreground">(optional)</span>
            </label>
            <Textarea
              id={`absence-note-${leadId}`}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              maxLength={500}
              rows={3}
              placeholder="e.g. Called the business — they only use a Facebook page."
            />
          </div>

          <DialogFooter>
            <Button variant="ghost" size="sm" onClick={() => setOpen(false)} disabled={confirm.isPending}>
              Cancel
            </Button>
            <Button
              size="sm"
              disabled={confirm.isPending}
              onClick={() =>
                confirm.mutate(note.trim() || null, {
                  onSuccess: () => {
                    setOpen(false);
                    setNote("");
                  },
                })
              }
            >
              {confirm.isPending && <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden />}
              {confirm.isPending ? "Confirming…" : "Confirm no website"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function formatDate(iso: string | null): string {
  if (!iso) return "";
  return new Date(iso).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}
