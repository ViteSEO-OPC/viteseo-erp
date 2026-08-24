"use client";

import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Building2, Trash2, Upload } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  useCompanyProfile,
  useUpdateCompanyProfile,
  useUploadCompanyLogo,
  useDeleteCompanyLogo,
} from "@/modules/hr/hooks/use-company";
import type { CompanyProfile } from "@/modules/hr/types/company";

/**
 * WHO THE EMPLOYER IS — the first section of Company → Settings.
 *
 * These fields are the letterhead: the payslip on screen, the payslip PDF, the payroll
 * workbook and the report PDF all print what is saved here. Editing the name here renames
 * every document, including ones already issued — a reprint goes out on today's stationery.
 */

// Mirrors UpdateCompanyProfileRequest, so the form catches what the API would reject.
const schema = z.object({
  legal_name: z
    .string()
    .trim()
    .min(1, "The company name is printed on every payslip — it cannot be blank.")
    .max(255),
  trade_name: z.string().trim().max(255),
  address: z.string().trim().max(500),
  tin: z.string().trim().max(32),
  sss_employer_number: z.string().trim().max(32),
  philhealth_employer_number: z.string().trim().max(32),
  pagibig_employer_number: z.string().trim().max(32),
  email: z.union([z.literal(""), z.email("Enter a valid email address")]),
  phone: z.string().trim().max(32),
  website: z.string().trim().max(255),
});

type FormValues = z.input<typeof schema>;

const EMPTY: FormValues = {
  legal_name: "",
  trade_name: "",
  address: "",
  tin: "",
  sss_employer_number: "",
  philhealth_employer_number: "",
  pagibig_employer_number: "",
  email: "",
  phone: "",
  website: "",
};

/** The API's nulls become "" for the inputs; blanks go back as null on save. */
function toForm(p: CompanyProfile): FormValues {
  return {
    legal_name: p.legal_name ?? "",
    trade_name: p.trade_name ?? "",
    address: p.address ?? "",
    tin: p.tin ?? "",
    sss_employer_number: p.sss_employer_number ?? "",
    philhealth_employer_number: p.philhealth_employer_number ?? "",
    pagibig_employer_number: p.pagibig_employer_number ?? "",
    email: p.email ?? "",
    phone: p.phone ?? "",
    website: p.website ?? "",
  };
}

function blankToNull(v: string): string | null {
  const t = v.trim();
  return t === "" ? null : t;
}

export function CompanyDetailsForm() {
  const { data: profile, isLoading, isError } = useCompanyProfile();
  const updateMut = useUpdateCompanyProfile();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

  // Fill the form once the row arrives, and re-baseline after every save so the
  // dirty state reflects "changed since last saved", not "changed since page load".
  useEffect(() => {
    if (profile) reset(toForm(profile));
  }, [profile, reset]);

  async function onSubmit(values: FormValues) {
    try {
      const saved = await updateMut.mutateAsync({
        legal_name: values.legal_name.trim(),
        trade_name: blankToNull(values.trade_name),
        address: blankToNull(values.address),
        tin: blankToNull(values.tin),
        sss_employer_number: blankToNull(values.sss_employer_number),
        philhealth_employer_number: blankToNull(values.philhealth_employer_number),
        pagibig_employer_number: blankToNull(values.pagibig_employer_number),
        email: blankToNull(values.email),
        phone: blankToNull(values.phone),
        website: blankToNull(values.website),
      });
      reset(toForm(saved));
      toast.success("Company details saved");
    } catch {
      toast.error("Could not save the company details. Please try again.");
    }
  }

  return (
    <section className="space-y-6">
      <div className="space-y-1">
        <h2 className="text-h3 font-bold text-foreground">Company details</h2>
        <p className="text-sm text-muted-foreground">
          Who the employer is. This is the letterhead on every payslip, payroll export and
          report — changing the name here changes all of them, including documents you
          re-download later.
        </p>
      </div>

      {isError ? (
        <p className="text-sm text-destructive">
          Could not load the company details. Please refresh.
        </p>
      ) : isLoading || !profile ? (
        <p className="text-sm text-muted-foreground">Loading company details…</p>
      ) : (
        <div className="rounded-card border border-border bg-card p-6">
          <LogoField profile={profile} />

          <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-6" noValidate>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                id="cp-legal-name"
                label="Registered name"
                hint="Printed on the payslip."
                error={errors.legal_name?.message}
                className="sm:col-span-2"
              >
                <Input
                  id="cp-legal-name"
                  placeholder="VITE SEO DIGITAL ADVERTISING OPC"
                  aria-invalid={!!errors.legal_name}
                  {...register("legal_name")}
                />
              </Field>

              <Field
                id="cp-trade-name"
                label="Trade name"
                hint="The everyday name. Optional."
                error={errors.trade_name?.message}
              >
                <Input id="cp-trade-name" placeholder="Viteseo" {...register("trade_name")} />
              </Field>

              <Field
                id="cp-phone"
                label="Phone"
                error={errors.phone?.message}
              >
                <Input id="cp-phone" placeholder="+63 2 8123 4567" {...register("phone")} />
              </Field>

              <Field
                id="cp-address"
                label="Address"
                hint="One line, exactly as it should print."
                error={errors.address?.message}
                className="sm:col-span-2"
              >
                <Input
                  id="cp-address"
                  placeholder="26L Burgundy Corporate Tower, 252 Sen. Gil Puyat Avenue, Makati City"
                  aria-invalid={!!errors.address}
                  {...register("address")}
                />
              </Field>

              <Field id="cp-email" label="Email" error={errors.email?.message}>
                <Input
                  id="cp-email"
                  type="email"
                  placeholder="hr@viteseo.ph"
                  aria-invalid={!!errors.email}
                  {...register("email")}
                />
              </Field>

              <Field id="cp-website" label="Website" error={errors.website?.message}>
                <Input id="cp-website" placeholder="https://viteseo.ph" {...register("website")} />
              </Field>
            </div>

            <div className="space-y-4">
              <div className="space-y-1">
                <h3 className="text-sm font-bold text-foreground">Employer registration</h3>
                <p className="text-sm text-muted-foreground">
                  Your numbers with the BIR and the agencies. Used on remittance paperwork.
                </p>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Field id="cp-tin" label="TIN" error={errors.tin?.message}>
                  <Input id="cp-tin" placeholder="000-000-000-000" {...register("tin")} />
                </Field>
                <Field id="cp-sss" label="SSS employer no." error={errors.sss_employer_number?.message}>
                  <Input id="cp-sss" placeholder="00-0000000-0" {...register("sss_employer_number")} />
                </Field>
                <Field
                  id="cp-philhealth"
                  label="PhilHealth employer no."
                  error={errors.philhealth_employer_number?.message}
                >
                  <Input
                    id="cp-philhealth"
                    placeholder="00-000000000-0"
                    {...register("philhealth_employer_number")}
                  />
                </Field>
                <Field
                  id="cp-pagibig"
                  label="Pag-IBIG employer no."
                  error={errors.pagibig_employer_number?.message}
                >
                  <Input id="cp-pagibig" placeholder="0000-0000-0000" {...register("pagibig_employer_number")} />
                </Field>
              </div>
            </div>

            <div className="flex items-center justify-end gap-3">
              {isDirty && (
                <span className="text-xs text-muted-foreground">Unsaved changes</span>
              )}
              <Button type="submit" disabled={isSubmitting || !isDirty}>
                {isSubmitting ? "Saving…" : "Save company details"}
              </Button>
            </div>
          </form>
        </div>
      )}
    </section>
  );
}

/** One labelled input, with its optional hint and error line. */
function Field({
  id,
  label,
  hint,
  error,
  className,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  error?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={["space-y-1.5", className].filter(Boolean).join(" ")}>
      <Label htmlFor={id}>{label}</Label>
      {children}
      {error ? (
        <p className="text-xs text-destructive">{error}</p>
      ) : hint ? (
        <p className="text-xs text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}

/**
 * The mark on the letterhead. Uploaded on its own, not with the form: it is a file, and
 * saving it separately keeps a half-filled form from blocking a logo change.
 */
function LogoField({ profile }: { profile: CompanyProfile }) {
  const uploadMut = useUploadCompanyLogo();
  const deleteMut = useDeleteCompanyLogo();
  const inputRef = useRef<HTMLInputElement>(null);
  // The signed URL 404s once its 30 minutes are up; a broken <img> would just show an icon.
  // Remembering WHICH url failed (rather than a bare flag) means a fresh upload's url is
  // tried again without an effect to reset anything.
  const [brokenUrl, setBrokenUrl] = useState<string | null>(null);
  const showLogo = !!profile.logo_url && brokenUrl !== profile.logo_url;

  async function onPick(file: File | undefined) {
    if (!file) return;
    try {
      await uploadMut.mutateAsync(file);
      toast.success("Logo updated");
    } catch {
      toast.error("Could not upload the logo. It must be a JPG, PNG or WebP under 2 MB.");
    } finally {
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  async function onRemove() {
    try {
      await deleteMut.mutateAsync();
      toast.success("Logo removed");
    } catch {
      toast.error("Could not remove the logo. Please try again.");
    }
  }

  const busy = uploadMut.isPending || deleteMut.isPending;

  return (
    <div className="flex flex-wrap items-center gap-5">
      <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-muted">
        {showLogo && profile.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element -- signed, short-lived URL off the private disk; not an optimizable static asset
          <img
            src={profile.logo_url}
            alt={`${profile.legal_name} logo`}
            className="size-full object-contain p-2"
            onError={() => setBrokenUrl(profile.logo_url)}
          />
        ) : (
          <Building2 className="size-8 text-muted-foreground" aria-hidden />
        )}
      </div>

      <div className="space-y-2">
        <p className="text-sm font-bold text-foreground">Company logo</p>
        <p className="max-w-md text-xs text-muted-foreground">
          Shown above the company name on the payslip and report PDFs. JPG, PNG or WebP, up
          to 2 MB. A wide, transparent PNG reads best.
        </p>
        <div className="flex flex-wrap gap-2">
          <input
            ref={inputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(e) => onPick(e.target.files?.[0])}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={busy}
            onClick={() => inputRef.current?.click()}
          >
            <Upload />
            {uploadMut.isPending
              ? "Uploading…"
              : profile.logo_url
                ? "Replace logo"
                : "Upload logo"}
          </Button>
          {profile.logo_url && (
            <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={onRemove}>
              <Trash2 />
              Remove
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
