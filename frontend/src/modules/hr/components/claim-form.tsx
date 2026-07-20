"use client";

import { useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { UploadCloud, FileCheck2 } from "lucide-react";

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

export function ClaimForm() {
  const createMut = useCreateReimbursement();
  const [receipt, setReceipt] = useState<File | null>(null);
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

  async function onSubmit(v: FormValues) {
    const form = new FormData();
    form.append("category", v.category);
    form.append("description", v.description);
    form.append("amount", v.amount);
    form.append("expense_date", v.expense_date);
    if (receipt) form.append("receipt", receipt);

    try {
      await createMut.mutateAsync(form);
      toast.success("Claim submitted");
      reset();
      setReceipt(null);
    } catch (err) {
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          if (field in schema.shape) {
            setError(field as keyof FormValues, { message: serverErrors[field][0] });
          } else {
            toast.error(serverErrors[field][0]);
          }
        }
      } else {
        toast.error("Something went wrong. Please try again.");
      }
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
          <Label>Receipt</Label>
          <label
            onDragOver={(e) => {
              e.preventDefault();
              setDragOver(true);
            }}
            onDragLeave={() => setDragOver(false)}
            onDrop={(e) => {
              e.preventDefault();
              setDragOver(false);
              const f = e.dataTransfer.files?.[0];
              if (f) setReceipt(f);
            }}
            className={cn(
              "flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border py-10 text-center transition-colors hover:border-brand/60",
              dragOver && "border-brand bg-brand-subtle",
            )}
          >
            {receipt ? (
              <>
                <FileCheck2 className="size-7 text-brand" />
                <span className="text-sm font-medium text-foreground">{receipt.name}</span>
                <span className="text-caption text-muted-foreground">Click to replace</span>
              </>
            ) : (
              <>
                <UploadCloud className="size-7 text-brand" />
                <span className="text-sm text-muted-foreground">
                  Click to upload or drag file here
                </span>
                <span className="text-caption text-muted-foreground">JPG, PNG or PDF · up to 5MB</span>
              </>
            )}
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.pdf"
              className="hidden"
              onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
            />
          </label>
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
