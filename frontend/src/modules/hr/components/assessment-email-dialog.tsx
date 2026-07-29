"use client";

import { useEffect } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ClipboardCheck } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

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
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import { useSendCandidateEmail } from "@/modules/hr/hooks/use-recruitment";
import type { Candidate } from "@/modules/hr/types/recruitment";

const schema = z
  .object({
    // Optional, but a URL when given — same shape as the shared optional validators.
    link: z
      .string()
      .trim()
      .max(500, "Keep the link under 500 characters")
      .refine(
        (v) => v === "" || z.url().safeParse(v).success,
        "Enter a full URL, e.g. https://…",
      ),
    instructions: z.string().trim().max(2000, "Keep it under 2000 characters"),
    deadline: z.string(),
  })
  // Every assessment differs, so there's no template to fall back on: without a link
  // or instructions the candidate would get an email that tells them nothing.
  .refine((v) => v.link !== "" || v.instructions !== "", {
    path: ["instructions"],
    message: "Add a link, instructions, or both.",
  });

type FormValues = z.infer<typeof schema>;

/**
 * Compose-on-send for the assessment email. Unlike the other stage emails, what an
 * assessment says depends on the role and the test, so HR writes it here. What is sent
 * is stored on the candidate, which also means re-sending starts from the same text.
 */
export function AssessmentEmailDialog({
  candidate,
  open,
  onOpenChange,
}: {
  candidate: Candidate;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const emailMut = useSendCandidateEmail();
  const alreadySent = candidate.emails_sent.includes("assessment");

  const {
    register,
    control,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { link: "", instructions: "", deadline: "" },
  });

  useEffect(() => {
    if (!open) return;
    // Prefill from the last send so a re-send repeats it rather than starting blank.
    reset({
      link: candidate.assessment_link ?? "",
      instructions: candidate.assessment_instructions ?? "",
      deadline: candidate.assessment_deadline ?? "",
    });
  }, [
    open,
    reset,
    candidate.assessment_link,
    candidate.assessment_instructions,
    candidate.assessment_deadline,
  ]);

  function onSubmit(v: FormValues) {
    emailMut.mutate(
      {
        id: candidate.id,
        type: "assessment",
        link: v.link || null,
        instructions: v.instructions || null,
        deadline: v.deadline || null,
      },
      {
        onSuccess: () => {
          toast.success(
            alreadySent ? "Assessment re-sent" : "Assessment sent",
            `${candidate.name} has the details at ${candidate.email}.`,
          );
          onOpenChange(false);
        },
        onError: () => toast.error("Could not send the assessment email."),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg bg-brand-subtle text-brand-foreground">
              <ClipboardCheck className="size-4" />
            </span>
            {alreadySent ? "Re-send assessment" : "Send assessment"}
          </DialogTitle>
          <DialogDescription>
            Goes to {candidate.name} at {candidate.email}. Paste the assessment link, add
            any instructions, and set a deadline if there is one.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="assessment-link">Assessment link</Label>
            <Input
              id="assessment-link"
              placeholder="https://forms.gle/…"
              aria-invalid={!!errors.link}
              {...register("link")}
            />
            {errors.link && <p className="text-xs text-destructive">{errors.link.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="assessment-instructions">Instructions</Label>
            <textarea
              id="assessment-instructions"
              rows={5}
              placeholder="What the assessment covers, how long it takes, anything they need to prepare."
              aria-invalid={!!errors.instructions}
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring aria-invalid:border-destructive"
              {...register("instructions")}
            />
            {errors.instructions && (
              <p className="text-xs text-destructive">{errors.instructions.message}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <Label>Deadline (optional)</Label>
            <Controller
              control={control}
              name="deadline"
              render={({ field }) => (
                <DatePicker
                  value={field.value}
                  onChange={field.onChange}
                  min={manilaToday()}
                  placeholder="No deadline"
                />
              )}
            />
          </div>

          <DialogFooter>
            <DialogClose asChild>
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </DialogClose>
            <Button type="submit" disabled={isSubmitting || emailMut.isPending}>
              {emailMut.isPending
                ? "Sending…"
                : alreadySent
                  ? "Re-send email"
                  : "Send email"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
