"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/core/ui/select";
import { useCreateFaq, useUpdateFaq } from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, FAQ_STATUSES, type Faq } from "@/modules/hr/types/faq";

const schema = z.object({
  question: z
    .string()
    .trim()
    .min(1, "Question is required")
    .max(255, "Keep the question under 255 characters"),
  answer: z
    .string()
    .trim()
    .min(1, "Answer is required")
    .max(5000, "Keep the answer under 5000 characters"),
  category: z.string().min(1, "Pick a category"),
  status: z.string().min(1),
});

type FormValues = z.infer<typeof schema>;

const emptyForm: FormValues = {
  question: "",
  answer: "",
  category: "general",
  status: "draft",
};

// Dot colours for the edit-mode selects. These mirror the table's category
// pills and StatusBadge so the same entry reads the same colour everywhere.
const CATEGORY_DOT: Record<string, string> = {
  leave: "bg-success",
  payroll: "bg-info",
  attendance: "bg-warning",
  benefits: "bg-violet",
  general: "bg-muted-foreground",
};

const STATUS_DOT: Record<string, string> = {
  published: "bg-success",
  draft: "bg-muted-foreground",
  archived: "bg-muted-foreground",
};

const controlClass =
  "h-10 w-full rounded-lg border border-input bg-transparent px-3.5 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

// tailwind-merge (via cn) runs on its default config, which doesn't know the
// project's type scale — it reads `text-label` as a text *colour* and strips it
// out whenever a real colour class follows. The arbitrary-length form is
// recognised as a font-size, so it survives the merge.
const labelClass = "text-[length:var(--text-label)] font-bold";

function Dot({ className }: { className?: string }) {
  return <span className={cn("size-2.5 shrink-0 rounded-full", className)} />;
}

export function FaqFormDialog({
  open,
  onOpenChange,
  faq,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Present = edit mode; absent = create mode. */
  faq?: Faq | null;
}) {
  const isEdit = !!faq;
  const createMut = useCreateFaq();
  const updateMut = useUpdateFaq();

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: emptyForm,
  });

  const category = watch("category");
  const status = watch("status");

  // Refill the form whenever the dialog opens (with the row's values on edit,
  // or blank defaults on create).
  useEffect(() => {
    if (open) {
      reset(
        faq
          ? {
              question: faq.question,
              answer: faq.answer,
              category: faq.category,
              status: faq.status,
            }
          : emptyForm,
      );
    }
  }, [open, faq, reset]);

  // Archiving is a row action on the table; offering it on create would only
  // invite making an entry nobody can read. Editing an archived FAQ still needs
  // the option so its status survives a save.
  const statusOptions = isEdit
    ? FAQ_STATUSES
    : FAQ_STATUSES.filter((s) => s.value !== "archived");

  async function onSubmit(values: FormValues) {
    try {
      if (faq) {
        await updateMut.mutateAsync({ id: faq.id, input: values });
        toast.success("FAQ updated");
      } else {
        await createMut.mutateAsync(values);
        toast.success(
          values.status === "published" ? "FAQ published" : "FAQ created (draft)",
        );
      }
      onOpenChange(false);
    } catch (err) {
      // Surface server-side validation on the field it belongs to.
      if (err instanceof AxiosError && err.response?.status === 422) {
        const serverErrors = err.response.data?.errors ?? {};
        for (const field of Object.keys(serverErrors)) {
          setError(field as keyof FormValues, {
            message: serverErrors[field][0],
          });
        }
      } else {
        toast.error("Could not save the FAQ.");
      }
    }
  }

  // The two frames are drawn to different metrics — edit is the roomier one.
  const pad = isEdit ? "px-7" : "px-6";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn("gap-0 p-0", isEdit ? "max-w-[560px]" : "max-w-[500px]")}
      >
        <DialogHeader className={cn("mb-0", pad, isEdit ? "pt-6 pb-5" : "py-6")}>
          {/* Sizes restated here for the same tailwind-merge reason as labelClass:
              the kit's own text-h3 / text-caption are stripped from DialogTitle
              and DialogDescription before they reach the DOM. */}
          <DialogTitle
            className={cn(
              "text-[length:var(--text-h3)]",
              isEdit ? "font-bold" : "font-extrabold",
            )}
          >
            {isEdit ? "Edit FAQ" : "New FAQ"}
          </DialogTitle>
          <DialogDescription className="text-[length:var(--text-label)]">
            {isEdit
              ? "Update the question, answer, and category. Published FAQs are visible to all employees."
              : "Add a frequently asked question"}
          </DialogDescription>
        </DialogHeader>

        <div className="h-px bg-border" />

        <form onSubmit={handleSubmit(onSubmit)} noValidate>
          <div className={cn("space-y-5 py-6", pad)}>
            <div className="space-y-1.5">
              <Label htmlFor="faq-question" className={labelClass}>
                Question
              </Label>
              <Input
                id="faq-question"
                className="h-10 px-3.5"
                placeholder="e.g. How do I file a leave request?"
                aria-invalid={!!errors.question}
                {...register("question")}
              />
              {errors.question && (
                <p className="text-xs text-destructive">
                  {errors.question.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="faq-answer" className={labelClass}>
                Answer
              </Label>
              <textarea
                id="faq-answer"
                placeholder="Enter the answer to this question..."
                aria-invalid={!!errors.answer}
                className={cn(
                  controlClass,
                  "resize-none py-3 placeholder:text-muted-foreground aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20",
                  isEdit ? "h-25" : "h-30",
                )}
                {...register("answer")}
              />
              {errors.answer && (
                <p className="text-xs text-destructive">
                  {errors.answer.message}
                </p>
              )}
            </div>

            {/* Create picks a category from chips; edit uses a dot select beside
                Status. Both frames are drawn that way. */}
            {isEdit ? (
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label htmlFor="faq-category" className={labelClass}>
                    Category
                  </Label>
                  <Select
                    value={category}
                    onValueChange={(v) =>
                      setValue("category", v, { shouldDirty: true })
                    }
                  >
                    <SelectTrigger id="faq-category" className={controlClass}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FAQ_CATEGORIES.map((c) => (
                        <SelectItem key={c.value} value={c.value}>
                          <Dot className={CATEGORY_DOT[c.value]} />
                          {c.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="faq-status" className={labelClass}>
                    Status
                  </Label>
                  <Select
                    value={status}
                    onValueChange={(v) =>
                      setValue("status", v, { shouldDirty: true })
                    }
                  >
                    <SelectTrigger id="faq-status" className={controlClass}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {statusOptions.map((s) => (
                        <SelectItem key={s.value} value={s.value}>
                          <Dot className={STATUS_DOT[s.value]} />
                          {s.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            ) : (
              <>
                <div className="space-y-2.5">
                  <Label className={labelClass}>Category</Label>
                  <div className="flex flex-wrap gap-2">
                    {FAQ_CATEGORIES.map((c) => {
                      const selected = category === c.value;
                      return (
                        <button
                          key={c.value}
                          type="button"
                          aria-pressed={selected}
                          onClick={() =>
                            setValue("category", c.value, { shouldDirty: true })
                          }
                          className={cn(
                            "rounded-full border-2 bg-card px-3.5 py-2 text-[length:var(--text-label)] transition-colors",
                            selected
                              ? "border-brand font-bold text-foreground"
                              : "border-border text-muted-foreground hover:bg-muted",
                          )}
                        >
                          {c.label}
                        </button>
                      );
                    })}
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label htmlFor="faq-status" className={labelClass}>
                      Status
                    </Label>
                    <Select
                      value={status}
                      onValueChange={(v) =>
                        setValue("status", v, { shouldDirty: true })
                      }
                    >
                      <SelectTrigger id="faq-status" className={controlClass}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {statusOptions.map((s) => (
                          <SelectItem key={s.value} value={s.value}>
                            {s.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </>
            )}
          </div>

          <div className="h-px bg-border" />

          <DialogFooter
            className={cn("mt-0", pad, isEdit ? "gap-3 py-4.5" : "pt-5 pb-6")}
          >
            <DialogClose asChild>
              <Button
                type="button"
                variant="ghost"
                size="lg"
                className="font-bold text-muted-foreground"
              >
                Cancel
              </Button>
            </DialogClose>
            <Button
              type="submit"
              size="lg"
              className="px-6 font-bold"
              disabled={isSubmitting}
            >
              {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Add FAQ"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
