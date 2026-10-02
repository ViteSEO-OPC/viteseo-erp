"use client";

import { useFeatures } from "@/core/use-features";

/**
 * Access in the other modules, given as the person is hired.
 *
 * Hiring used to grant an HR account and nothing else, so a new hire who was
 * there to work on projects had a login and nowhere to go until someone noticed
 * and granted it from another module's Team screen. This puts that grant on the
 * form HR is already filling in.
 *
 * Mirrors `App\Support\GrantableRoles` on the backend, which is the authority:
 * it re-checks the allowlist, the org's entitlements and the trainee cap, so a
 * stale copy here can only ever be refused, never over-grant.
 *
 * Only ADDS. Taking access away stays in each module's own Team screen, where
 * the admin doing it can see what they are removing.
 */

/** Module feature key => its roles, NARROWEST FIRST. The order is load-bearing. */
const MODULES: { feature: string; label: string; roles: { value: string; label: string; hint: string }[] }[] = [
  {
    feature: "pms",
    label: "Projects",
    roles: [
      { value: "pm_member", label: "Member", hint: "Sees the work and moves their own tasks." },
      { value: "pm_apm", label: "Manager", hint: "Creates and deletes projects, approves tasks." },
    ],
  },
  {
    feature: "crm",
    label: "CRM",
    roles: [
      { value: "crm_bd", label: "Business developer", hint: "Works their own leads." },
    ],
  },
  {
    feature: "accounting",
    label: "Accounting",
    roles: [
      { value: "acc_viewer", label: "Viewer", hint: "Read-only across the books." },
      { value: "acc_accountant", label: "Accountant", hint: "Posts entries and payroll." },
    ],
  },
];

/**
 * Employment statuses whose tier is not a choice — they may only hold the
 * narrowest role a module offers. Kept in step with GrantableRoles::NARROW_STATUSES.
 */
const NARROW_STATUSES = ["ojt", "project_based", "seasonal"];

export function GrantAccessSection({
  value,
  onChange,
  employmentStatus,
}: {
  value: string[];
  onChange: (next: string[]) => void;
  employmentStatus: string;
}) {
  const { data: features } = useFeatures();

  // Permissive while entitlements load: the backend refuses anything the org has
  // not availed, so the worst case here is a box that turns into a clear error.
  const availed = features?.modules;
  const shown = MODULES.filter((m) => availed === undefined || availed.includes(m.feature));

  const narrow = NARROW_STATUSES.includes(employmentStatus);

  if (shown.length === 0) return null;

  /** One role per module: picking a tier replaces whatever that module had. */
  function pick(moduleRoles: string[], role: string | null) {
    const others = value.filter((r) => !moduleRoles.includes(r));
    onChange(role ? [...others, role] : others);
  }

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-sm font-semibold">Access to other systems</h3>
        <p className="text-xs text-muted-foreground">
          Optional. They can always be given access later from each system&apos;s Team screen —
          this only saves a second trip.
        </p>
      </div>

      {narrow && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
          A trainee, part-time or project-based hire may only hold the narrowest tier, so the
          higher ones are unavailable.
        </p>
      )}

      {shown.map((m) => {
        const roleValues = m.roles.map((r) => r.value);
        const selected = value.find((r) => roleValues.includes(r)) ?? "";

        return (
          <fieldset key={m.feature} className="rounded-lg border p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {m.label}
            </legend>

            <label className="flex items-center gap-2 py-1 text-sm">
              <input
                type="radio"
                name={`grant-${m.feature}`}
                checked={selected === ""}
                onChange={() => pick(roleValues, null)}
              />
              <span className="text-muted-foreground">No access</span>
            </label>

            {m.roles.map((r, i) => {
              const disabled = narrow && i > 0;
              return (
                <label
                  key={r.value}
                  className={`flex items-start gap-2 py-1 text-sm ${disabled ? "opacity-40" : ""}`}
                >
                  <input
                    type="radio"
                    name={`grant-${m.feature}`}
                    className="mt-1"
                    disabled={disabled}
                    checked={selected === r.value}
                    onChange={() => pick(roleValues, r.value)}
                  />
                  <span>
                    {r.label}
                    <span className="block text-xs text-muted-foreground">{r.hint}</span>
                  </span>
                </label>
              );
            })}
          </fieldset>
        );
      })}
    </div>
  );
}
