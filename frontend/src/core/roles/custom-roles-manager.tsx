"use client";

import { useId, useMemo, useState } from "react";
import { ChevronDown, Pencil, Plus, Trash2 } from "lucide-react";
import { z } from "zod";

import { useValidatedForm } from "@/core/forms";
import { requiredTrimmedString } from "@/core/forms/validation";
import { notify as toast } from "@/core/lib/notify";
import { cn } from "@/core/lib/utils";
import { Button, Field, Input, Modal } from "@/core/ui";
import { useConfirm } from "@/core/ui/use-confirm";
import {
  readRoleWriteFailure,
  useCapabilities,
  useCreateCustomRole,
  useCustomRoles,
  useDeleteCustomRole,
  usePresetRoles,
  useUpdateCustomRole,
  type Capability,
  type CapabilityFeature,
  type CustomRole,
  type RoleFeature,
} from "@/core/roles/use-custom-roles";
import { presetRoleLabel, TEAM_MODULES } from "@/core/team/use-team-accounts";

/**
 * The org's role builder, shared by every portal.
 *
 * ONE SCREEN, THREE DOORS. TeamOra, Zerem and Sprintly each let their own admin
 * in, because each administers its own people — but the roles they build are the
 * org's, not the module's, and the endpoints behind this are org-wide. So the
 * screen is written once here and mounted in each portal, which is also why it
 * paints only in platform tokens: those are defined in globals.css and every
 * portal theme (crm-theme, zerem-theme) is additive on top of them.
 *
 * NOT A PAGE OF ITS OWN. This is the second half of the team page, never a
 * separate route: building a role and putting somebody in it is one task, and
 * splitting it across two navigation entries is what stopped operators finding
 * either. Everything created here lands in the "Custom roles" group of the
 * member pickers directly above it.
 *
 * NO GATE OF ITS OWN. Every route that mounts this is already behind an admin
 * check — TeamOra's layout, Zerem's PAGE_ACCESS, Sprintly's isManager rail — and
 * the endpoints re-check on the server. A fourth check here would only be a
 * fourth place to get the role list wrong.
 */

const roleFormSchema = z.object({
  name: requiredTrimmedString("Give the role a name", 100),
  permissions: z.array(z.string()).min(1, "Tick at least one capability"),
});

type RoleFormValues = z.infer<typeof roleFormSchema>;

/**
 * The product name of the portal this is mounted in.
 *
 * Read off TEAM_MODULES so the builder and the member pickers above it can never
 * call the same system two different things. The keys differ on purpose: a role
 * feature says "pms" where a team module says "projects", the same two axes the
 * backend's CapabilityRegistry maps by hand rather than by a shared prefix.
 */
const PORTAL_PRODUCT: Record<RoleFeature, string> = {
  hr: TEAM_MODULES.hr.product,
  crm: TEAM_MODULES.crm.product,
  pms: TEAM_MODULES.projects.product,
  accounting: TEAM_MODULES.accounting.product,
};

/**
 * Whether a capability block is the mounted portal's own.
 *
 * A block is keyed by the FEATURE that sells it — "hr" for the module core,
 * "hr.payroll" for a sub-function — and only the CORE opens.
 *
 * It used to open the core AND everything under it, which read fine for an org on
 * several systems and badly for one on a single system: every block matched, none
 * folded, and a client who bought only the HRIS met five expanded blocks — the
 * wall the folding exists to prevent. The add-on packs a plan sells are exactly
 * the blocks worth folding, whoever the org is.
 */
function belongsToPortal(featureKey: string, portal: RoleFeature): boolean {
  return featureKey === portal;
}

/** "leave_requests" -> "Leave requests". */
function groupLabel(group: string): string {
  const words = group.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The action half of a capability's label.
 *
 * The server labels every capability with its subject ("Leave requests: approve")
 * — the same words the group heading above the checkbox already carries. HR core
 * alone is ~60 capabilities over ~25 subjects, and repeating the subject on each
 * one is what turns that block into a wall. The heading keeps the subject, the
 * checkbox keeps the verb, and a label with no subject prefix is left whole.
 */
function actionLabel(capability: Capability): string {
  const [subject, action] = capability.label.split(": ");
  return action && subject ? action : capability.label;
}

/** Capabilities of one feature, split into the subjects they act on. */
function subjectsOf(feature: CapabilityFeature): { group: string; items: Capability[] }[] {
  const bySubject = new Map<string, Capability[]>();
  for (const capability of feature.capabilities) {
    const bucket = bySubject.get(capability.group);
    if (bucket) bucket.push(capability);
    else bySubject.set(capability.group, [capability]);
  }
  return [...bySubject].map(([group, items]) => ({ group, items }));
}

const CHECKBOX = "size-4 shrink-0 rounded border-input accent-brand";

/** A tinted statement block — the plan refusal and the dropped-capability notice. */
function Notice({
  tone,
  title,
  children,
}: {
  tone: "danger" | "warning";
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        "rounded-lg border p-3",
        tone === "danger"
          ? "border-danger bg-danger-subtle"
          : "border-warning bg-warning-subtle",
      )}
    >
      <p className="text-sm font-bold text-dark-teal">{title}</p>
      <div className="mt-1 text-sm text-dark-teal/80">{children}</div>
    </div>
  );
}

/** A capability chip on a saved role. */
function CapabilityChip({ label, orphaned }: { label: string; orphaned?: boolean }) {
  return (
    <span
      className={cn(
        "rounded-full px-2 py-0.5 text-xs",
        orphaned
          ? "bg-warning-subtle text-dark-teal"
          : "bg-muted text-muted-foreground",
      )}
      title={orphaned ? "No longer part of this organisation’s plan." : undefined}
    >
      {label}
    </span>
  );
}

/**
 * The builder — a role's name and the capabilities it grants.
 *
 * EVERY SYSTEM IS LISTED HERE, AND THAT IS THE FEATURE. A role belongs to the
 * ORG, not to one module: one person holds one account and one role covering
 * their whole job, and a role built in Sprintly is offered in TeamOra's picker.
 * Opened from a portal it nonetheless read as a bug — Sprintly's admin saw CRM
 * blocks and no sentence anywhere saying why — so the rule is now stated above
 * the blocks, and the portal's own block is the one that starts open.
 */
function RoleBuilderDialog({
  features,
  portal,
  editing,
  onClose,
}: {
  features: CapabilityFeature[];
  /** The portal the builder was opened from — which block starts open. */
  portal: RoleFeature;
  /** null = building a new role. */
  editing: CustomRole | null;
  onClose: () => void;
}) {
  const create = useCreateCustomRole();
  const update = useUpdateCustomRole();
  const panelId = useId();

  /**
   * Which blocks the operator has opened or folded by hand.
   *
   * An override map rather than one-flag-per-block seeded at mount: the catalog
   * can arrive or refetch while the dialog is open, and a block that appears
   * then must still get its default rather than defaulting to shut.
   */
  const [toggled, setToggled] = useState<Record<string, boolean>>({});

  // Everything is open when the portal owns no block at all — an org whose plan
  // covers this system only through another module's capabilities would
  // otherwise meet a dialog with every block shut.
  const portalHasBlock = features.some((f) => belongsToPortal(f.feature, portal));
  const isOpen = (feature: CapabilityFeature) =>
    toggled[feature.feature] ?? (!portalHasBlock || belongsToPortal(feature.feature, portal));
  const folded = features.filter((f) => !belongsToPortal(f.feature, portal)).length;

  // How many SYSTEMS the org is on, not how many blocks. "a role you build here can
  // be picked in the others too" is only true when there are others; to a client who
  // bought the HRIS alone it promises a door that is not in their building.
  const systemCount = new Set(features.map((f) => f.feature.split(".")[0])).size;

  /**
   * This portal's blocks first, the rest after, each in the catalog's own order.
   *
   * Opening the right block is not enough on its own: the catalog is keyed
   * alphabetically, so TeamOra's admin met three folded CRM headers before
   * reaching the one block that was open for them.
   */
  const ordered = useMemo(
    () => [
      ...features.filter((f) => belongsToPortal(f.feature, portal)),
      ...features.filter((f) => !belongsToPortal(f.feature, portal)),
    ],
    [features, portal],
  );

  const grantable = useMemo(
    () => new Set(features.flatMap((f) => f.capabilities.map((c) => c.key))),
    [features],
  );

  /**
   * Permissions this role holds that the org can no longer grant — what a plan
   * downgrade leaves behind. They are dropped from the tick list rather than
   * carried silently into the request, because sending one back would be refused
   * by the plan gate and the operator would have no way to see which tick did it.
   */
  const dropped = useMemo(
    () => (editing?.permissions ?? []).filter((key) => !grantable.has(key)),
    [editing, grantable],
  );

  const form = useValidatedForm(roleFormSchema, {
    defaultValues: {
      name: editing?.name ?? "",
      permissions: (editing?.permissions ?? []).filter((key) => grantable.has(key)),
    },
  });

  const picked = form.watch("permissions");
  const pickedSet = useMemo(() => new Set(picked), [picked]);

  // The plan gate's answer, kept out of the form: it is a statement about the
  // subscription, not a complaint about a field the operator can retype.
  const [planRefusal, setPlanRefusal] = useState<string | null>(null);

  const setPicked = (next: string[]) =>
    form.setValue("permissions", next, { shouldValidate: true, shouldDirty: true });

  const toggle = (key: string) =>
    setPicked(pickedSet.has(key) ? picked.filter((k) => k !== key) : [...picked, key]);

  const toggleFeature = (feature: CapabilityFeature, selectAll: boolean) => {
    const keys = feature.capabilities.map((c) => c.key);
    setPicked(
      selectAll
        ? [...picked, ...keys.filter((k) => !pickedSet.has(k))]
        : picked.filter((k) => !keys.includes(k)),
    );
  };

  const isSaving = create.isPending || update.isPending;

  const submit = form.handleSubmit((values: RoleFormValues) => {
    setPlanRefusal(null);

    const onError = (error: unknown) => {
      const failure = readRoleWriteFailure(error);
      if (failure.planRefusal) setPlanRefusal(failure.planRefusal);
      if (failure.nameError) form.setError("name", { message: failure.nameError });
      if (!failure.planRefusal && !failure.nameError) toast.error(failure.message);
    };

    const onSuccess = () => {
      toast.success(editing ? `“${values.name}” updated.` : `“${values.name}” created.`);
      onClose();
    };

    if (editing) {
      update.mutate({ id: editing.id, ...values }, { onSuccess, onError });
    } else {
      create.mutate(values, { onSuccess, onError });
    }
  });

  return (
    <Modal
      open
      onClose={onClose}
      width={760}
      title={editing ? `Edit “${editing.name}”` : "New role"}
      footer={
        <div className="flex items-center justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving}>
            Cancel
          </Button>
          <Button type="button" onClick={submit} disabled={isSaving}>
            {isSaving ? "Saving…" : editing ? "Save changes" : "Create role"}
          </Button>
        </div>
      }
    >
      <form onSubmit={submit} className="space-y-5">
        {planRefusal ? (
          <Notice tone="danger" title="Outside this organisation’s plan">
            {planRefusal} Untick it, or add the function to the subscription first.
          </Notice>
        ) : null}

        {dropped.length > 0 ? (
          <Notice tone="warning" title="Some capabilities are no longer available">
            This role still holds {dropped.length}{" "}
            {dropped.length === 1 ? "capability" : "capabilities"} the organisation
            no longer avails ({dropped.join(", ")}). Saving removes{" "}
            {dropped.length === 1 ? "it" : "them"}.
          </Notice>
        ) : null}

        <Field label="Role name" error={form.formState.errors.name?.message}>
          <Input
            {...form.register("name")}
            placeholder="e.g. Payroll Clerk, Lead Reviewer"
            aria-invalid={form.formState.errors.name ? true : undefined}
            className="max-w-sm"
          />
        </Field>

        <div>
          <div className="flex items-baseline justify-between gap-3">
            <p className="text-sm font-bold text-ink">What can this role do?</p>
            <p className="text-xs text-ink-soft">
              {picked.length} selected
            </p>
          </div>
          {/* Said here because here is where it surprises people: an admin who
              opened this from one portal finds the other systems' capabilities in
              it, and without this sentence reads that as a leak rather than as
              the rule the platform is built on. */}
          <p className="mt-1 text-xs text-ink-soft">
            {systemCount > 1 ? (
              <>
                A role belongs to the ORGANISATION, not to one system — one person,
                one account, one role covering their whole job. So every system this
                organisation availed is here, and a role you build in{" "}
                {PORTAL_PRODUCT[portal]} can be picked in the others too.
              </>
            ) : (
              <>
                A role belongs to the ORGANISATION, not to one screen — one person,
                one account, one role covering their whole job.
              </>
            )}
          </p>
          {/* The blocks are the org's availed functions, in the plan's own words —
              an operator should recognise them as the things they bought. */}
          <p className="mt-1 text-xs text-ink-soft">
            {folded > 0 ? (
              <>
                {PORTAL_PRODUCT[portal]}&rsquo;s capabilities are open below and the
                other {folded === 1 ? "block is" : `${folded} blocks are`} folded —
                open one to grant from it.{" "}
              </>
            ) : null}
            Only the functions this organisation availed are listed.
          </p>

          {form.formState.errors.permissions ? (
            <p className="mt-2 text-sm text-danger">
              {form.formState.errors.permissions.message}
            </p>
          ) : null}

          <div className="mt-3 space-y-4">
            {ordered.map((feature) => {
              const keys = feature.capabilities.map((c) => c.key);
              const selected = keys.filter((k) => pickedSet.has(k)).length;
              const allSelected = selected === keys.length;
              const open = isOpen(feature);
              const blockId = `${panelId}-${feature.feature}`;

              return (
                <fieldset
                  key={feature.feature}
                  className="rounded-xl border border-line"
                >
                  <legend className="sr-only">{feature.label}</legend>
                  <div
                    className={cn(
                      "flex items-center justify-between gap-3 bg-muted/40 px-4 py-2.5",
                      open && "border-b border-line",
                    )}
                  >
                    {/* The count stays in the header, open or shut. Folding a block
                        that already grants six capabilities must not hide that it
                        does — an admin editing a role would have to open every
                        block to find out what it holds. */}
                    <button
                      type="button"
                      onClick={() =>
                        setToggled((current) => ({ ...current, [feature.feature]: !open }))
                      }
                      aria-expanded={open}
                      aria-controls={blockId}
                      className="flex min-w-0 flex-1 items-center gap-2 text-left"
                    >
                      <ChevronDown
                        className={cn(
                          "size-4 shrink-0 text-ink-soft transition-transform",
                          !open && "-rotate-90",
                        )}
                        aria-hidden
                      />
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-bold text-ink">
                          {feature.label}
                        </span>
                        <span className="block text-xs text-ink-soft">
                          {selected} of {keys.length} selected
                        </span>
                      </span>
                    </button>
                    <Button
                      type="button"
                      variant="ghost"
                      size="xs"
                      onClick={() => toggleFeature(feature, !allSelected)}
                    >
                      {allSelected ? "Clear" : "Select all"}
                    </Button>
                  </div>

                  <div id={blockId} className="divide-y divide-line" hidden={!open}>
                    {subjectsOf(feature).map(({ group, items }) => (
                      <div
                        key={group}
                        className="grid gap-1.5 px-4 py-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-baseline sm:gap-4"
                      >
                        <p className="text-sm font-medium text-ink">
                          {groupLabel(group)}
                        </p>
                        <div className="flex flex-wrap gap-x-5 gap-y-2">
                          {items.map((capability) => (
                            <label
                              key={capability.key}
                              className="flex items-center gap-2 text-sm text-ink-soft"
                              title={capability.key}
                            >
                              <input
                                type="checkbox"
                                className={CHECKBOX}
                                checked={pickedSet.has(capability.key)}
                                onChange={() => toggle(capability.key)}
                              />
                              <span>{actionLabel(capability)}</span>
                            </label>
                          ))}
                        </div>
                      </div>
                    ))}
                  </div>
                </fieldset>
              );
            })}
          </div>
        </div>

        {editing ? (
          <p className="text-xs text-ink-soft">
            Changes apply immediately to everyone who already holds this role.
          </p>
        ) : null}
      </form>
    </Modal>
  );
}

/**
 * Read-only reference: the built-in roles and what each one grants.
 *
 * Scoped to the portal it is mounted in. The endpoint answers for the whole
 * platform, so unfiltered this listed six roles on TeamOra while the member
 * picker beside it offered two -- four roles an admin could read about and
 * never assign, under a sentence promising they were in the picker.
 */
function PresetRoles({ feature }: { feature: RoleFeature }) {
  const { data, isLoading } = usePresetRoles();

  const presets = useMemo(
    () => (data ?? []).filter((preset) => preset.feature === feature),
    [data, feature],
  );

  if (isLoading || presets.length === 0) return null;

  return (
    <section>
      <h2 className="text-lg font-bold text-ink">Built-in roles</h2>
      <p className="mt-1 max-w-2xl text-sm text-ink-soft">
        Defined by the platform and not editable here. These are the “Built-in
        roles” group in the role pickers above. Check what one grants before
        assigning it — if none of them fits, build a custom role below.
      </p>

      <div className="mt-4 divide-y divide-line rounded-xl border border-line bg-card">
        {presets.map((preset) => (
          <div
            key={preset.key}
            className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-baseline sm:gap-6"
          >
            <p className="shrink-0 text-sm font-semibold text-ink sm:w-52">
              {presetRoleLabel(preset.key) ?? preset.label}
            </p>
            {preset.capabilities.length === 0 ? (
              // The endpoint returns an empty list both for a role that grants
              // nothing and for one this deployment never provisioned. Say only
              // what is actually known rather than asserting either.
              <p className="text-sm text-ink-soft">
                No capabilities published for this role.
              </p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {preset.capabilities.map((capability) => (
                  <CapabilityChip key={capability.key} label={capability.label} />
                ))}
              </div>
            )}
          </div>
        ))}
      </div>
    </section>
  );
}

/**
 * The org's custom roles: list, build, edit, delete — plus the built-in roles as
 * a read-only reference underneath.
 */
export function CustomRolesManager({
  feature,
  showBuiltInRoles = true,
  className,
}: {
  /** The portal this is mounted in, so the built-in list matches its picker. */
  feature: RoleFeature;
  /**
   * Zerem documents the built-in roles itself, in more detail than a chip list
   * can carry. Rendering both put two answers to "what are the built-in roles?"
   * on one page, which is the confusion this merge existed to remove.
   */
  showBuiltInRoles?: boolean;
  className?: string;
}) {
  const capabilities = useCapabilities();
  const roles = useCustomRoles();
  const remove = useDeleteCustomRole();
  const { confirm, confirmDialog } = useConfirm();

  // `undefined` = the builder is closed; `null` = open on a new role.
  const [editing, setEditing] = useState<CustomRole | null | undefined>(undefined);

  const features = useMemo(() => capabilities.data ?? [], [capabilities.data]);
  const labelByKey = useMemo(() => {
    const map = new Map<string, string>();
    for (const feature of features) {
      for (const capability of feature.capabilities) map.set(capability.key, capability.label);
    }
    return map;
  }, [features]);

  const hasCatalog = features.length > 0;

  return (
    <div className={cn("space-y-10", className)}>
      {confirmDialog}

      {/* Reference before builder: an operator decides whether the platform
          already has what they need BEFORE inventing a role, and "if none of
          them fits, build one below" only reads correctly in this order. */}
      {showBuiltInRoles ? <PresetRoles feature={feature} /> : null}

      <section>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-ink">Custom roles</h2>
            <p className="mt-1 max-w-2xl text-sm text-ink-soft">
              Create the roles your organisation actually uses and tick exactly what
              each one can do. The tick list is bounded by the functions you availed,
              and anything you create here appears in the “Custom roles” group of
              every role picker above.
            </p>
          </div>
          <Button
            type="button"
            onClick={() => setEditing(null)}
            disabled={!hasCatalog || capabilities.isLoading}
          >
            <Plus />
            New role
          </Button>
        </div>

        {capabilities.isError ? (
          <p className="mt-4 rounded-xl border border-line bg-card p-4 text-sm text-danger">
            Couldn’t load what this organisation can grant. Reload to try again.
          </p>
        ) : null}

        {!capabilities.isLoading && !capabilities.isError && !hasCatalog ? (
          <p className="mt-4 rounded-xl border border-line bg-card p-4 text-sm text-ink-soft">
            This organisation has no availed functions to grant yet, so there is
            nothing to build a role from.
          </p>
        ) : null}

        <div className="mt-4">
          {roles.isLoading ? (
            <p className="rounded-xl border border-line bg-card p-4 text-sm text-ink-soft">
              Loading roles…
            </p>
          ) : (roles.data ?? []).length === 0 ? (
            <p className="rounded-xl border border-dashed border-line p-4 text-sm text-ink-soft">
              No custom roles yet.
            </p>
          ) : (
            <div className="divide-y divide-line rounded-xl border border-line bg-card">
              {(roles.data ?? []).map((role) => (
                <div
                  key={role.id}
                  className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start sm:justify-between"
                >
                  <div className="min-w-0">
                    <p className="font-semibold text-ink">{role.name}</p>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                      {role.permissions.map((key) => (
                        <CapabilityChip
                          key={key}
                          label={labelByKey.get(key) ?? key}
                          // Held but no longer grantable — a plan downgrade left it
                          // behind, and it is flagged rather than quietly listed.
                          orphaned={hasCatalog && !labelByKey.has(key)}
                        />
                      ))}
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setEditing(role)}
                      disabled={!hasCatalog}
                    >
                      <Pencil />
                      Edit
                    </Button>
                    <Button
                      type="button"
                      variant="destructive-outline"
                      size="sm"
                      onClick={() =>
                        confirm({
                          title: `Delete “${role.name}”?`,
                          description:
                            "Anyone holding this role loses the capabilities it granted.",
                          confirmLabel: "Delete",
                          onConfirm: () =>
                            remove.mutate(role.id, {
                              onError: () => toast.error("Couldn’t delete the role."),
                            }),
                        })
                      }
                    >
                      <Trash2 />
                      Delete
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </section>

      {editing !== undefined ? (
        // Remounted per target so the form starts from that role's values —
        // cheaper and harder to get wrong than resetting an always-mounted form.
        <RoleBuilderDialog
          key={editing?.id ?? "new"}
          features={features}
          portal={feature}
          editing={editing}
          onClose={() => setEditing(undefined)}
        />
      ) : null}
    </div>
  );
}
