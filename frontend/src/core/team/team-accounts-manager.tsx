"use client";

import { useId, useState } from "react";
import { ChevronDown, Copy, KeyRound, RefreshCw, Trash2, UserPlus } from "lucide-react";
import { z } from "zod";

import { useValidatedForm } from "@/core/forms";
import { requiredTrimmedString } from "@/core/forms/validation";
import { notify as toast } from "@/core/lib/notify";
import { cn } from "@/core/lib/utils";
import { useCustomRoles, type CustomRole } from "@/core/roles/use-custom-roles";
import {
  Button,
  Field,
  Input,
  Modal,
  ProgressBar,
  Select,
} from "@/core/ui";
import { useConfirm } from "@/core/ui/use-confirm";
import { useCopyToClipboard } from "@/core/use-copy-to-clipboard";
import {
  customRoleValue,
  readTeamWriteFailure,
  TEAM_MODULES,
  useAddTeamMember,
  useRemoveTeamMember,
  useSetTeamMemberRole,
  useTeamAccounts,
  type CreatedTeamMember,
  type TeamMember,
  type TeamModuleKey,
  type TeamRolePreset,
} from "@/core/team/use-team-accounts";

/**
 * The org's staff accounts, shared by every portal.
 *
 * ONE SCREEN, THREE DOORS — the same arrangement as the role builder below it,
 * for the same reason: a client's admin administers their OWN people, and until
 * this existed they could pay for ten seats and had no way to fill even one
 * outside Zerem. The module is a prop because the endpoint and its two presets
 * are the only things that differ (see TEAM_MODULES).
 *
 * MAKING A ROLE AND PUTTING SOMEBODY IN IT IS ONE TASK. This used to be a page
 * of its own, a navigation entry away from the role builder, and an operator who
 * went looking for either found neither. The two now sit on ONE page, and the
 * join between them is the row's role picker: build a role below and it appears
 * in the "Custom roles" group next to every member. That picker is the feature —
 * everything else here is in service of it.
 *
 * NO GATE OF ITS OWN. Every route that mounts this is already behind an admin
 * check — TeamOra's layout, Sprintly's RequireManager, Zerem's PAGE_ACCESS — and
 * each endpoint re-checks on the server. A third check here would only be a third
 * place to get the role list wrong.
 *
 * SEATS ARE THE ORG'S, NOT THE MODULE'S. The count includes every member of the
 * org whichever system they work in, so the number here matches what TeamOra,
 * Sprintly and billing all report.
 *
 * THE MEMBER LIST IS THIS SYSTEM'S PEOPLE, not the org's — see
 * {@link TeamMembersSection}. That is possible only because the add panel's
 * email field is now a door for a colleague as well as for a stranger: POST
 * takes an existing address as a GRANT, so nobody has to be listed here to be
 * given access here.
 */

const addMemberSchema = z.object({
  name: requiredTrimmedString("Enter the person's full name"),
  email: requiredTrimmedString("Enter their working email").email(
    "That doesn't look like an email address",
  ),
  role: requiredTrimmedString("Pick an access level"),
  // Optional in the form, required by the API — blank means "generate one for
  // me", which submit does before the request leaves. Refined rather than a
  // union with `""`, so a too-short password gets this sentence instead of
  // zod's generic union complaint.
  password: z
    .string()
    .refine((value) => value === "" || value.length >= 8, "At least 8 characters"),
});

type AddMemberValues = z.infer<typeof addMemberSchema>;

/**
 * A temporary password the operator hands over.
 *
 * The endpoint requires one (min 8) and returns nothing secret, so a blank field
 * is filled here rather than server-side. Excludes the character pairs that get
 * misread when a password is passed on by voice or on paper (O/0, I/l/1).
 */
function generateTemporaryPassword(length = 16): string {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%";
  const picks = new Uint32Array(length);
  crypto.getRandomValues(picks);
  return Array.from(picks, (n) => chars[n % chars.length]).join("");
}

/** A tinted statement block — the seat cap and the write refusals. */
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
        tone === "danger" ? "border-danger bg-danger-subtle" : "border-warning bg-warning-subtle",
      )}
    >
      <p className="text-sm font-bold text-dark-teal">{title}</p>
      <div className="mt-1 text-sm text-dark-teal/80">{children}</div>
    </div>
  );
}

/**
 * The options every role picker on this screen offers.
 *
 * Two groups, and the second one is the point: an org's own roles stand beside
 * the built-in ones in the SAME control, so an operator who builds "Payroll
 * Clerk" below sees where it landed without being told. The group names match
 * the section headings further down the page on purpose — "Built-in roles" and
 * "Custom roles" are the same two words in both places.
 */
function RoleOptions({
  presets,
  custom,
}: {
  presets: readonly TeamRolePreset[];
  custom: readonly CustomRole[];
}) {
  return (
    <>
      <optgroup label="Built-in roles">
        {presets.map((preset) => (
          <option key={preset.value} value={preset.value}>
            {preset.label}
          </option>
        ))}
      </optgroup>
      {custom.length > 0 ? (
        <optgroup label="Custom roles">
          {custom.map((role) => (
            <option key={role.id} value={customRoleValue(role.id)}>
              {role.name}
            </option>
          ))}
        </optgroup>
      ) : null}
    </>
  );
}

/**
 * What a role value is called on this screen — a preset by its PICKER name, one
 * of the org's own by the name the org gave it, and anything else by its raw
 * value so a role nobody can name is still visible rather than blank.
 */
function roleLabelOf(
  value: string,
  presets: readonly TeamRolePreset[],
  custom: readonly CustomRole[],
): string {
  return (
    presets.find((preset) => preset.value === value)?.label ??
    custom.find((role) => customRoleValue(role.id) === value)?.name ??
    value
  );
}

/** Whether a role value is one the picker above can actually render. */
function isOfferedRole(
  value: string,
  presets: readonly TeamRolePreset[],
  custom: readonly CustomRole[],
): boolean {
  return (
    presets.some((preset) => preset.value === value) ||
    custom.some((role) => customRoleValue(role.id) === value)
  );
}

/** One label/value line of a hand-over card. */
function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-3">
      <span className="text-sm text-ink-soft">{label}</span>
      <span
        className={cn(
          "text-sm font-semibold text-ink",
          // A password is never clipped — it is the one value on these cards that
          // has to be read off the screen by hand when the copy button fails.
          mono ? "rounded bg-muted px-2 py-0.5 font-mono break-all" : "truncate",
        )}
      >
        {value}
      </span>
    </div>
  );
}

/**
 * The created account's sign-in details.
 *
 * Shown ONCE, from the value that was just posted. Nothing here is cached or
 * re-fetchable: the server stores only the hash, so closing this card is the
 * last time anyone sees the password, and the copy is what gets handed over.
 *
 * Exported, and typed to the two fields it renders rather than to
 * CreatedTeamMember, because HR's People form hands an account over the same
 * way. A second copy of this card would be a second place for the warning above
 * to drift out of date.
 */
export function CredentialsCard({
  account,
  password,
  onDone,
}: {
  account: { name: string; email: string };
  password: string;
  onDone: () => void;
}) {
  const { copy } = useCopyToClipboard();

  const copyDetails = async () => {
    const text = `Name: ${account.name}\nEmail: ${account.email}\nTemporary password: ${password}`;
    if (await copy(text)) toast.success("Sign-in details copied.");
    else toast.error("Couldn’t copy — select the password and copy it by hand.");
  };

  return (
    <div className="space-y-4">
      <Notice tone="warning" title="Shown once — copy it now">
        The password is not stored anywhere you can read it back. If this card
        closes before you copy it, the account still works but you’ll have to
        reset the password to get a new one.
      </Notice>

      <div className="divide-y divide-line rounded-xl border border-line bg-card">
        <DetailRow label="Name" value={account.name} />
        <DetailRow label="Email" value={account.email} />
        <DetailRow label="Temporary password" value={password} mono />
      </div>

      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button type="button" variant="outline" onClick={copyDetails}>
          <Copy />
          Copy sign-in details
        </Button>
        <Button type="button" onClick={onDone}>
          Done
        </Button>
      </div>
    </div>
  );
}

/**
 * A colleague who was given access — the other half of the add panel's outcome.
 *
 * Deliberately shows NO credentials. The grant put a role on the account they
 * already sign in with, and their password was neither read nor changed; a card
 * shaped like the one next door would send an admin looking for a password to
 * hand over that nobody ever set.
 */
function GrantedCard({
  member,
  product,
  roleLabel,
  onDone,
}: {
  member: CreatedTeamMember;
  product: string;
  roleLabel: string;
  onDone: () => void;
}) {
  return (
    <div className="space-y-4">
      <p className="text-sm text-ink-soft">
        {member.name} already had a Vite SEO Systems account, so no second one was
        created and no extra seat was used. They can sign in to {product} now, with
        the password they already use.
      </p>

      <div className="divide-y divide-line rounded-xl border border-line bg-card">
        <DetailRow label="Name" value={member.name} />
        <DetailRow label="Email" value={member.email} />
        <DetailRow label={`${product} role`} value={roleLabel} />
      </div>

      <div className="flex justify-end">
        <Button type="button" onClick={onDone}>
          Done
        </Button>
      </div>
    </div>
  );
}

/**
 * Add a team member — an inline panel, not a dialog.
 *
 * A dialog put the one thing an empty team page exists for behind a button, and
 * hid the seat count with it. The fields are four short ones; they belong on the
 * page. The ONE thing that still opens over it is the outcome card, because a
 * created account is a hand-over the operator must finish before anything else.
 *
 * THE EMAIL DECIDES WHAT THIS DOES. It is the only door to the systems a
 * colleague does not yet have — the member list no longer shows people without
 * access, so there is no row to change. The panel does not ask the server who
 * owns the address before submitting (only Zerem has a lookup endpoint, and its
 * own panel uses it); it reads the answer off the response's `granted` and says
 * which of the two happened afterwards.
 */
export function AddTeamMemberSection({ module }: { module: TeamModuleKey }) {
  const { roles, product } = TEAM_MODULES[module];
  const accounts = useTeamAccounts(module);
  const customRoles = useCustomRoles();
  const add = useAddTeamMember(module);
  const { copy } = useCopyToClipboard();

  // The password lives in component state only, and only until the card is
  // dismissed — never in the query cache, and never re-read from the server. It
  // is kept beside the member because a GRANT ignored it: which card is shown
  // reads `member.granted`, never the presence of a password.
  const [added, setAdded] = useState<{ member: CreatedTeamMember; password: string } | null>(
    null,
  );
  // The seat cap's answer, kept out of the form: it is a statement about the
  // subscription, not a complaint about a field the operator can retype.
  const [seatRefusal, setSeatRefusal] = useState<string | null>(null);

  // Defaults to the LAST preset — every module lists its management tier first,
  // and a form that arrives pre-set to "admin" makes the un-read submit the
  // privileged one.
  const form = useValidatedForm(addMemberSchema, {
    defaultValues: { name: "", email: "", role: roles[roles.length - 1].value, password: "" },
  });

  const seats = accounts.data?.seats;
  const atLimit = seats ? seats.used >= seats.limit : false;
  const password = form.watch("password");

  const copyPassword = async () => {
    if (!password) return;
    if (await copy(password)) toast.success("Password copied.");
  };

  const submit = form.handleSubmit((values: AddMemberValues) => {
    setSeatRefusal(null);

    const handover = values.password || generateTemporaryPassword();

    add.mutate(
      { ...values, password: handover },
      {
        onSuccess: (member) => {
          toast.success(
            member.granted
              ? `${member.name} now has ${product} access.`
              : `${member.name} can now sign in to ${product}.`,
          );
          setAdded({ member, password: handover });
          // Only the identity fields are cleared: an admin adding three people
          // in a row is usually putting them all on the same role.
          form.reset({ name: "", email: "", password: "", role: values.role });
        },
        onError: (error) => {
          const failure = readTeamWriteFailure(error, "Couldn’t create the account.");
          if (failure.seatRefusal) setSeatRefusal(failure.seatRefusal);
          for (const [field, message] of Object.entries(failure.fieldErrors)) {
            form.setError(field as keyof AddMemberValues, { message });
          }
          if (!failure.seatRefusal && Object.keys(failure.fieldErrors).length === 0) {
            toast.error(failure.message);
          }
        },
      },
    );
  });

  return (
    <section>
      {/* Full width, like the Members and role sections below it. A narrower form
          would read better on its own, but this section does not sit on its own:
          three stacked sections at two different widths look like a mistake, and
          the operator sees the page, not the form. */}
      {/* Seats read in the heading rather than as a card of their own: the number
          only ever matters while deciding whether to add somebody, and that is
          the sentence directly above the form that adds them. */}
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-bold text-ink">Add a team member</h2>
        {seats ? (
          <p className="text-sm font-semibold text-ink-soft">
            {seats.used} of {seats.limit} seats used
          </p>
        ) : null}
      </div>
      {seats ? (
        <ProgressBar
          value={seats.limit > 0 ? (seats.used / seats.limit) * 100 : 100}
          className="mt-2"
        />
      ) : null}
      {/* The door, stated. One person holds ONE account for the whole platform,
          so most addresses typed here already belong to a colleague — and since
          the list below shows only the people who use {product}, this sentence
          is now the only place an operator learns how to reach the others. */}
      <p className="mt-2 max-w-2xl text-sm text-ink-soft">
        Start with the working email. If it already belongs to someone in this
        organisation, this gives them {product} access on the sign-in they
        already have — no second account, no extra seat, and their name and
        password are left alone. A new address creates an account instead. A seat
        is one account in this organisation, counted across every system it uses
        — not per module.
      </p>

      <div className="mt-4 space-y-4 rounded-xl border border-line bg-card p-4 sm:p-6">
        {seatRefusal ? (
          <Notice tone="danger" title="No seats left">
            {seatRefusal}
          </Notice>
        ) : null}

        {atLimit ? (
          <Notice tone="warning" title="Every seat is in use">
            All {seats?.limit} seats are taken, so no NEW account can be created.
            Giving an existing colleague {product} access still works — they
            already occupy a seat. To sign somebody up, remove a person who has
            left or ask the platform admin to add a block of seats.
          </Notice>
        ) : null}

        <form onSubmit={submit}>
          {/* Stated, not enforced. The form used to disable itself at the cap,
              which was right while every submit was a create; a grant spends no
              seat, so disabling it now refuses a write the server would honour.
              A create at the cap still fails, and says so in the server's words.
              `min-w-0` because a fieldset's default `min-inline-size: min-content`
              stops the grid inside it from shrinking on a narrow screen. */}
          <fieldset className="min-w-0 space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <Field label="Full name" error={form.formState.errors.name?.message}>
                <Input
                  {...form.register("name")}
                  placeholder="e.g. Andrea Lim"
                  autoComplete="off"
                  aria-invalid={form.formState.errors.name ? true : undefined}
                />
              </Field>

              <Field label="Working email" error={form.formState.errors.email?.message}>
                <Input
                  {...form.register("email")}
                  type="email"
                  placeholder="name@company.com"
                  autoComplete="off"
                  aria-invalid={form.formState.errors.email ? true : undefined}
                />
              </Field>

              <Field
                label="Temporary password"
                error={form.formState.errors.password?.message}
              >
                <div className="flex gap-2">
                  <Input
                    {...form.register("password")}
                    className="font-mono"
                    placeholder="Blank generates one"
                    autoComplete="off"
                    aria-invalid={form.formState.errors.password ? true : undefined}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Generate a password"
                    onClick={() =>
                      form.setValue("password", generateTemporaryPassword(), {
                        shouldValidate: true,
                      })
                    }
                  >
                    <RefreshCw />
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Copy the password"
                    onClick={copyPassword}
                    disabled={!password}
                  >
                    <Copy />
                  </Button>
                </div>
              </Field>

              <Field label="Role" error={form.formState.errors.role?.message}>
                <Select {...form.register("role")}>
                  <RoleOptions presets={roles} custom={customRoles.data ?? []} />
                </Select>
              </Field>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
              <Button type="submit" disabled={add.isPending}>
                <UserPlus />
                {add.isPending ? "Adding…" : "Add member"}
              </Button>
              <p className="text-xs text-ink-soft">
                A new account&rsquo;s password is shown once, right after this, for
                you to hand over — ask them to change it at first sign-in. For a
                colleague who already has a sign-in, the name and password typed
                here are ignored and there is nothing to hand over.
              </p>
            </div>
          </fieldset>
        </form>
      </div>

      {added ? (
        <Modal
          open
          onClose={() => setAdded(null)}
          width={560}
          title={added.member.granted ? `${product} access granted` : "Account created"}
        >
          {added.member.granted ? (
            <GrantedCard
              member={added.member}
              product={product}
              roleLabel={roleLabelOf(added.member.role, roles, customRoles.data ?? [])}
              onDone={() => setAdded(null)}
            />
          ) : (
            <CredentialsCard
              account={added.member}
              password={added.password}
              onDone={() => setAdded(null)}
            />
          )}
        </Modal>
      ) : null}
    </section>
  );
}

/**
 * A member of the org who holds a role in THIS module — what the list is made of.
 *
 * The narrowing is the screen's rule in the type system: a row always has a role
 * to show, so the picker can never render blank and the "no access" placeholder
 * it used to carry has nothing left to describe.
 */
type ActiveMember = TeamMember & { role: string };

/** One member row: who they are, what they can do here, and the way out. */
function MemberRow({
  member,
  module,
  customRoles,
  onRemove,
  actions,
}: {
  member: ActiveMember;
  module: TeamModuleKey;
  customRoles: readonly CustomRole[];
  /** Omitted where the portal closes accounts somewhere else. */
  onRemove?: () => void;
  /** The portal's own per-row actions, rendered after the role picker. */
  actions?: React.ReactNode;
}) {
  const { roles } = TEAM_MODULES[module];
  const setRole = useSetTeamMemberRole(module);

  const changeRole = (role: string) =>
    setRole.mutate(
      { id: member.id, role },
      {
        onSuccess: () =>
          toast.success(`${member.name} is now ${roleLabelOf(role, roles, customRoles)}.`),
        onError: (error) =>
          toast.error(readTeamWriteFailure(error, "Couldn’t change the access level.").message),
      },
    );

  // A role the picker cannot offer: a custom role still loading, or one deleted
  // out from under the member. Rendered as its own option so the select shows
  // what they hold instead of going blank — which would happen to exactly the
  // people the custom-role feature was built for.
  const unlisted = !isOfferedRole(member.role, roles, customRoles);

  return (
    <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="truncate font-semibold text-ink">
          {member.name}
          {member.is_you ? (
            <span className="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
              You
            </span>
          ) : null}
        </p>
        <p className="truncate text-sm text-ink-soft">{member.email}</p>
      </div>

      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {/* The server refuses to re-role or remove the caller, so their own row
            offers neither — the alternative is a control that always 422s. */}
        <Select
          value={member.role}
          onChange={(e) => changeRole(e.target.value)}
          disabled={member.is_you || setRole.isPending}
          aria-label={`Role for ${member.name}`}
          className="w-56"
        >
          {unlisted ? (
            <option value={member.role} disabled>
              {member.role_label ?? member.role}
            </option>
          ) : null}
          <RoleOptions presets={roles} custom={customRoles} />
        </Select>
        {onRemove ? (
          <Button
            type="button"
            variant="destructive-outline"
            size="sm"
            onClick={onRemove}
            disabled={member.is_you}
          >
            <Trash2 />
            Remove
          </Button>
        ) : null}
        {actions}
      </div>
    </div>
  );
}

/**
 * The people who can reach THIS module, each with the role they hold in it.
 *
 * IT USED TO LIST THE WHOLE ORG. On the provider's own org that meant twenty-two
 * rows reading "No Zerem access" in front of the four that were Zerem's, and the
 * same shape in the other two portals — a member list whose majority was people
 * who are not members of it. Everyone was listed because the role picker on the
 * row was the ONLY way to grant access: POST could not take a colleague's
 * address. Now it can, so the list can be what its heading says.
 *
 * THE OTHERS ARE COUNTED, NOT LISTED. A colleague without access is a fact this
 * screen still has to be able to state: seats are org-wide, so "4 members" under
 * "26 of 30 seats used" reads as a bug otherwise, and an admin granting access
 * needs the person's address. They sit behind one collapsed line, name and email
 * only — no role picker, because the add panel's email field is the one door and
 * a second one beside it is how this screen ended up listing everybody.
 *
 * Split out from the add form so Zerem can mount the members half on its own:
 * it creates accounts through /users, which is the only path that carries the
 * colleague lookup and grant, and a second create form beside it would be two
 * doors to one act.
 */
export function TeamMembersSection({
  module,
  description,
  allowRemove = true,
  rowActions,
}: {
  module: TeamModuleKey;
  description?: React.ReactNode;
  /**
   * Off where the portal already closes accounts under a stricter guard. Zerem
   * reserves deletion for a super admin, and /crm/team's DELETE would hand the
   * same power to any CRM admin — so its row offers the stricter /users delete
   * through {@link rowActions} instead, and this list does not offer the way in.
   */
  allowRemove?: boolean;
  /**
   * Extra actions for one row, from the portal that mounted this.
   *
   * A slot rather than more props, because what belongs here is whatever the
   * portal can do that /<module>/team has no words for — Zerem alone has five
   * such actions, each on its own /users endpoint, and none of them is a concept
   * TeamOra or Sprintly shares. The row's identity, role picker and self-guards
   * stay here so all three portals keep the SAME row.
   */
  rowActions?: (member: TeamMember) => React.ReactNode;
}) {
  const { product } = TEAM_MODULES[module];
  const accounts = useTeamAccounts(module);
  const customRoles = useCustomRoles();
  const remove = useRemoveTeamMember(module);
  const { confirm, confirmDialog } = useConfirm();
  const [showOthers, setShowOthers] = useState(false);
  const othersId = useId();

  // Every endpoint answers for the whole ORG and nulls the role of anyone who
  // holds nothing here, which is exactly the split this screen needs: the roster
  // is the non-null half, and the null half is the count under it.
  const everyone = accounts.data?.members ?? [];
  const members = everyone.filter((member): member is ActiveMember => member.role !== null);
  const others = everyone.filter((member) => member.role === null);

  const confirmRemoval = (member: TeamMember) =>
    confirm({
      title: `Remove ${member.name}?`,
      description: (
        <>
          They lose access to every system this organisation uses, not just{" "}
          {product}, and the seat is freed. A person belongs to one organisation,
          so this closes their account — the work they created stays.
        </>
      ),
      confirmLabel: "Remove",
      onConfirm: () =>
        remove.mutate(member.id, {
          onSuccess: () => toast.success(`${member.name} removed.`),
          onError: (error) =>
            toast.error(readTeamWriteFailure(error, "Couldn’t remove the account.").message),
        }),
    });

  return (
    <section>
      {confirmDialog}

      <h2 className="text-lg font-bold text-ink">Members</h2>
      <p className="mt-1 max-w-2xl text-sm text-ink-soft">
        {description ?? (
          <>
            The people who can sign in to {product}, and what each of them can
            reach. The picker offers the built-in roles and every custom role you
            have defined below.
          </>
        )}
      </p>

      {accounts.isError ? (
        <p className="mt-4 rounded-xl border border-line bg-card p-4 text-sm text-danger">
          Couldn’t load this organisation’s accounts. Reload to try again.
        </p>
      ) : null}

      <div className="mt-4">
        {accounts.isLoading ? (
          <p className="rounded-xl border border-line bg-card p-4 text-sm text-ink-soft">
            Loading accounts…
          </p>
        ) : members.length === 0 ? (
          <p className="rounded-xl border border-dashed border-line p-4 text-sm text-ink-soft">
            Nobody has {product} access yet. Add someone above — by their email if
            they already work in another system.
          </p>
        ) : (
          <div className="divide-y divide-line rounded-xl border border-line bg-card">
            {members.map((member) => (
              <MemberRow
                key={member.id}
                member={member}
                module={module}
                customRoles={customRoles.data ?? []}
                onRemove={allowRemove ? () => confirmRemoval(member) : undefined}
                actions={rowActions?.(member)}
              />
            ))}
          </div>
        )}
      </div>

      {/* The rest of the org: counted always, listed only on request. The count
          is what reconciles a short list with an org-wide seat number, and the
          addresses are what an admin needs to grant access — the act itself
          belongs to the add panel, so nothing here is clickable. */}
      {others.length > 0 ? (
        <div className="mt-3">
          <button
            type="button"
            onClick={() => setShowOthers((open) => !open)}
            aria-expanded={showOthers}
            aria-controls={othersId}
            className="flex items-center gap-1.5 text-xs font-semibold text-ink-soft transition-colors hover:text-ink"
          >
            <ChevronDown
              className={cn("size-3.5 transition-transform", !showOthers && "-rotate-90")}
              aria-hidden
            />
            {others.length} other {others.length === 1 ? "person" : "people"} in this
            organisation {others.length === 1 ? "has" : "have"} no {product} access
          </button>

          {showOthers ? (
            <div id={othersId} className="mt-2 rounded-xl border border-dashed border-line p-4">
              <p className="text-xs text-ink-soft">
                They work in this organisation&rsquo;s other systems and hold a seat
                already. To bring one of them into {product}, put their email in the
                form above and pick a role — that grants access to the account they
                have, and costs no extra seat.
              </p>
              <ul className="mt-3 space-y-1.5">
                {others.map((member) => (
                  <li key={member.id} className="flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span className="font-medium text-ink">{member.name}</span>
                    <span className="text-ink-soft">{member.email}</span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      ) : null}

      <p className="mt-3 flex items-start gap-2 text-xs text-ink-soft">
        <KeyRound className="mt-0.5 size-3.5 shrink-0" aria-hidden />
        <span>
          One person holds ONE account for the whole platform. Adding a colleague
          who already signs in to another system gives them {product} access on
          that same account — it never creates a second one, and it takes no
          second seat.
        </span>
      </p>
    </section>
  );
}

/** Both halves of the staff-account job, in the order an admin works them. */
export function TeamAccountsManager({
  module,
  className,
}: {
  module: TeamModuleKey;
  className?: string;
}) {
  return (
    <div className={cn("space-y-10", className)}>
      <AddTeamMemberSection module={module} />
      <TeamMembersSection module={module} />
    </div>
  );
}
