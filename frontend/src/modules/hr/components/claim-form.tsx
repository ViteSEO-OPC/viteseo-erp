"use client";

import { useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { UploadCloud, FileCheck2, X } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { useCreateReimbursement } from "@/modules/hr/hooks/use-reimbursements";
import {
  REIMBURSEMENT_CATEGORIES,
  categoryLabel,
} from "@/modules/hr/types/reimbursement";

const schema = z.object({
  category: z.string().min(1, "Select a category"),
  description: z.string().min(1, "Description is required"),
  amount: z.string().min(1, "Amount is required"),
  // Defaulted to today and not shown — a claim is filed for the current date.
  expense_date: z.string().min(1, "Date is required"),
});

type FormValues = z.infer<typeof schema>;

// Kept in lockstep with StoreReimbursementRequest on the backend.
const MAX_FILES = 5;
const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB per file
const ACCEPTED_EXT_RE = /\.(jpe?g|png|pdf|heic|heif)$/i;

/** Validate one file by name + size; returns an error string or null. */
function fileError(file: File): string | null {
  if (!ACCEPTED_EXT_RE.test(file.name)) {
    return `"${file.name}" isn't a supported type (JPG, PNG, PDF or HEIC).`;
  }
  if (file.size > MAX_FILE_BYTES) {
    return `"${file.name}" is larger than 5MB.`;
  }
  return null;
}

export function ClaimForm() {
  const createMut = useCreateReimbursement();
  const [receipts, setReceipts] = useState<File[]>([]);
  const [receiptError, setReceiptError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      category: "",
      description: "",
      amount: "",
      expense_date: new Date().toISOString().slice(0, 10),
    },
  });

  /** Merge newly picked files into the selection, validating each. */
  function addFiles(incoming: FileList | File[] | null) {
    if (!incoming) return;
    setReceiptError(null);

    const picked = Array.from(incoming);
    const valid: File[] = [];
    for (const f of picked) {
      const err = fileError(f);
      if (err) {
        setReceiptError(err);
        continue;
      }
      valid.push(f);
    }

    setReceipts((prev) => {
      // De-dupe by name+size so re-picking the same file doesn't stack.
      const merged = [...prev];
      for (const f of valid) {
        if (!merged.some((m) => m.name === f.name && m.size === f.size)) {
          merged.push(f);
        }
      }
      if (merged.length > MAX_FILES) {
        setReceiptError(`You can attach at most ${MAX_FILES} receipts.`);
        return merged.slice(0, MAX_FILES);
      }
      return merged;
    });
  }

  function removeReceipt(index: number) {
    setReceiptError(null);
    setReceipts((prev) => prev.filter((_, i) => i !== index));
  }

  async function onSubmit(v: FormValues) {
    const form = new FormData();
    form.append("category", v.category);
    form.append("description", v.description);
    form.append("amount", v.amount);
    form.append("expense_date", v.expense_date);
    receipts.forEach((f) => form.append("receipts[]", f));

    try {
      await createMut.mutateAsync(form);
      toast.success("Claim submitted");
      reset();
      setReceipts([]);
      setReceiptError(null);
    } catch (err) {
      if (err instanceof AxiosError) {
        // Payload too large — the file(s) exceeded a server/proxy limit before
        // validation could run. Surface it on the receipt field, not a toast.
        if (err.response?.status === 413) {
          setReceiptError(
            "Your receipt files are too large to upload. Try smaller images (under 5MB each).",
          );
          return;
        }
        if (err.response?.status === 422) {
          const serverErrors: Record<string, string[]> = err.response.data?.errors ?? {};
          for (const field of Object.keys(serverErrors)) {
            const message = serverErrors[field][0];
            if (field in schema.shape) {
              setError(field as keyof FormValues, { message });
            } else if (field.startsWith("receipt")) {
              // "receipts", "receipts.0", "receipt" → show by the upload box.
              setReceiptError(message);
            } else {
              toast.error(message);
            }
          }
          return;
        }
      }
      toast.error("Something went wrong. Please try again.");
    }
  }

  return (
    <div className="rounded-card border border-border bg-card p-6">
      <h2 className="text-h3 font-bold text-foreground">Submit a Claim</h2>

      <form onSubmit={handleSubmit(onSubmit)} className="mt-4 space-y-4" noValidate>
        <div className="space-y-1.5">
          <Label>Category</Label>
          <Controller
            control={control}
            name="category"
            render={({ field }) => (
              <SimpleSelect
                value={field.value}
                onValueChange={field.onChange}
                placeholder="Select category"
                aria-invalid={!!errors.category}
                options={REIMBURSEMENT_CATEGORIES.map((c) => ({
                  value: c,
                  label: categoryLabel(c),
                }))}
              />
            )}
          />
          {errors.category && (
            <p className="text-xs text-destructive">{errors.category.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label>Description</Label>
          <Input placeholder="Client visit — Grab rides" {...register("description")} />
          {errors.description && (
            <p className="text-xs text-destructive">{errors.description.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label>Amount</Label>
          <Input type="number" min="0" step="0.01" placeholder="₱ 2,400" {...register("amount")} />
          {errors.amount && (
            <p className="text-xs text-destructive">{errors.amount.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label>Receipts</Label>
          <label
            onDragOver={(e) => {
              e.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => {
              e.preventDefault();
              setDragOver(false);
              addFiles(e.dataTransfer.files);
            }}
            className={cn(
              "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border py-8 text-center transition-colors hover:border-brand/60",
              dragOver && "border-brand bg-brand-subtle",
            )}
          >
            <UploadCloud className="size-7 text-brand" />
            <span className="text-sm text-muted-foreground">
              Click to upload or drag files here
            </span>
            <span className="text-caption text-muted-foreground">
              JPG, PNG, PDF or HEIC · up to 5MB each · max {MAX_FILES} files
            </span>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.pdf,.heic,.heif"
              multiple
              className="hidden"
              onChange={(e) => {
                addFiles(e.target.files);
                // Reset so re-selecting the same file still fires onChange.
                e.target.value = "";
              }}
            />
          </label>

          {receipts.length > 0 && (
            <ul className="space-y-1.5">
              {receipts.map((f, i) => (
                <li
                  key={`${f.name}-${f.size}-${i}`}
                  className="flex items-center gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2"
                >
                  <FileCheck2 className="size-4 shrink-0 text-brand" />
                  <span className="min-w-0 flex-1 truncate text-sm text-foreground">
                    {f.name}
                  </span>
                  <button
                    type="button"
                    onClick={() => removeReceipt(i)}
                    className="shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:bg-danger/10 hover:text-danger"
                    aria-label={`Remove ${f.name}`}
                  >
                    <X className="size-4" />
                  </button>
                </li>
              ))}
            </ul>
          )}

          {receiptError && (
            <p className="text-xs text-destructive">{receiptError}</p>
          )}
        </div>

        <Button type="submit" className="h-10 w-full" disabled={isSubmitting}>
          {isSubmitting ? "Submitting…" : "Submit claim"}
        </Button>
        <p className="text-center text-caption text-muted-foreground">
          Approved claims appear in your next payslip.
        </p>
      </form>
    </div>
  );
}
