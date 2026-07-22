"use client";

import { useRef, useState } from "react";
import { RelationshipSelect } from "@/modules/crm/components/shared/relationship-select/relationship-select";
import { CreateNicheModal } from "@/modules/crm/components/shared/niche-select/create-niche-modal";
import { AssignLeadsModal } from "@/modules/crm/components/shared/niche-select/assign-leads-modal";
import { useNiches } from "@/modules/crm/hooks/use-niches";

/**
 * Internal stand-in for "no niche". base-ui treats an empty string as *nothing selected* and falls
 * back to the placeholder, so a literal "" option could never render as a chosen row. This sentinel
 * is translated back to "" on the way out, keeping the component's public contract — and the
 * nullable API payload — unchanged: callers still send `""` → `industry: null`.
 */
const CLEAR_VALUE = "__niche:none__";

type Props = {
  /**
   * The selected niche NAME — the exact string persisted to `lead.industry`. Not an id: the
   * registry is a vocabulary, and leads store the value itself (see the niches migration).
   */
  value: string;
  onChange: (value: string) => void;
  /**
   * Show the "+ Add Business Niche…" row. Defaults to FALSE so a read context can never expose
   * creation by accident — per the PO decision, only the Create Lead and Edit Lead forms pass true.
   * The Leads and Nearby filters must leave this off.
   */
  allowCreate?: boolean;
  /**
   * Offer a "No Business Niche" option that clears the selection back to `""`.
   *
   * `lead.industry` is nullable and the Edit Lead drawer's previous free-text input could be
   * emptied, so a select without this would quietly remove the ability to UNSET a niche. Off by
   * default: the Create Lead wizard marks the field required, and an explicit "no niche" row there
   * would read as an endorsed choice rather than an escape hatch.
   */
  allowClear?: boolean;
  disabled?: boolean;
  id?: string;
  triggerClassName?: string;
  "aria-label"?: string;
};

/**
 * The single Business Niche selector — one component behind Create Lead and Edit Lead, so the two
 * forms cannot drift into offering different vocabularies (before this, the wizard had five
 * hardcoded options while the drawer was a free-text input).
 *
 * Built on the Lead Relationship Selector Standard (RelationshipSelect) rather than a bespoke
 * dropdown, so label resolution and keyboard behaviour stay in one place.
 *
 * Options render in the registry's own order — built-ins, then custom, each alphabetical — with NO
 * section headers (PO decision: order communicates the grouping).
 */
export function NicheSelect({
  value,
  onChange,
  allowCreate = false,
  allowClear = false,
  disabled,
  id,
  triggerClassName,
  "aria-label": ariaLabel = "Business Niche",
}: Props) {
  const { niches, isLoading } = useNiches();
  // Sequence 1 (create) → Sequence 2 (assign) chained from the "+ Add Business Niche…" dropdown row.
  const [createOpen, setCreateOpen] = useState(false);
  const [assignNiche, setAssignNiche] = useState<string | null>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);

  const options = niches.map((niche) => ({ value: niche.name, label: niche.name }));

  // Safeguard for a lead whose niche is not (yet) in the registry — an Excel import or a scraper
  // ingest can still write any string, and auto-registration on those paths is deliberately on hold
  // pending PO review. Without this the drawer would render an empty selector for such a lead and a
  // save could silently wipe a real value. Offering it keeps the current value selectable and
  // truthful; it disappears from the list once the value is registered.
  const isUnregistered = value !== "" && !options.some((option) => option.value === value);
  const withUnregistered = isUnregistered ? [{ value, label: value }, ...options] : options;
  // "No Business Niche" leads the list — it is the empty state of the field, not a niche.
  const allOptions = allowClear
    ? [{ value: CLEAR_VALUE, label: "No Business Niche" }, ...withUnregistered]
    : withUnregistered;

  const focusTrigger = () => requestAnimationFrame(() => triggerRef.current?.focus());

  /**
   * Sequence 1 → Sequence 2 handoff: the new niche is registered, so select it into the form
   * (`onChange`) right away, close the create dialog, and open the optional Assign dialog for it.
   * Focus returns to the trigger once the Assign dialog is dismissed (Done), so the user lands back
   * in the lead form with the new niche selected.
   */
  const handleCreated = (name: string) => {
    onChange(name);
    setCreateOpen(false);
    setAssignNiche(name);
  };

  const handleCreateOpenChange = (open: boolean) => {
    setCreateOpen(open);
    // Cancelling Create (without a successful save) returns focus to the trigger.
    if (!open) focusTrigger();
  };

  const handleAssignOpenChange = (open: boolean) => {
    if (!open) {
      setAssignNiche(null);
      focusTrigger();
    }
  };

  return (
    <>
      <RelationshipSelect
        // "" is the field's empty state. With a clear row available it maps to that row, so the
        // trigger reads "No Business Niche" instead of an ambiguous placeholder; without one it
        // stays "" and base-ui shows the placeholder exactly as before.
        value={allowClear && value === "" ? CLEAR_VALUE : value}
        onValueChange={(next) => onChange(next === CLEAR_VALUE ? "" : next)}
        options={allOptions}
        placeholder={
          isLoading
            ? "Loading…"
            : withUnregistered.length === 0
              ? "No business niches yet"
              : "Select niche…"
        }
        // Interim first-use state: the registry is empty until the canonical built-in list is
        // approved and seeded, so the dropdown says so rather than looking broken.
        emptyLabel={
          allowCreate
            ? "No business niches yet — add the first one below."
            : "No business niches yet."
        }
        disabled={disabled}
        id={id}
        aria-label={ariaLabel}
        triggerClassName={triggerClassName}
        triggerRef={triggerRef}
        footerAction={
          allowCreate ? { label: "+ Add Business Niche…", onSelect: () => setCreateOpen(true) } : undefined
        }
      />
      {allowCreate ? (
        <>
          {/* Sequence 1: create/register the niche. */}
          <CreateNicheModal
            open={createOpen}
            onOpenChange={handleCreateOpenChange}
            onCreated={handleCreated}
          />
          {/* Sequence 2: optional lead assignment, opened once a niche is created. Keyed by name so a
              fresh (search-first) instance mounts per niche. */}
          {assignNiche ? (
            <AssignLeadsModal
              key={assignNiche}
              open
              onOpenChange={handleAssignOpenChange}
              niche={assignNiche}
            />
          ) : null}
        </>
      ) : null}
    </>
  );
}
