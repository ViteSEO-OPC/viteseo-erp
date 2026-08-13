"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useForm, Controller } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { Mail, Paperclip, X } from "lucide-react";

import { notify as toast } from "@/core/lib/notify";
import { formatDateTime } from "@/core/lib/format";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
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
import { RichTextEditor } from "@/modules/hr/ui/rich-text-editor";
import { manilaToday } from "@/modules/hr/lib/manila-date";
import {
  useCandidateEmailDraft,
  useSendCandidateEmail,
} from "@/modules/hr/hooks/use-recruitment";
import { STAGE_EMAIL, type Candidate } from "@/modules/hr/types/recruitment";

/** Mirrors the server's per-file and per-send caps. */
const MAX_FILES = 5;
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const MAX_RECIPIENTS = 10;

/**
 * Cc and Bcc are typed the way people type them — one field, addresses separated by a
 * comma, a semicolon or a newline pasted out of somewhere else.
 */
function parseAddresses(raw: string): string[] {
  return raw
    .split(/[,;\n]/)
    .map((address) => address.trim())
    .filter(Boolean);
}

const addressList = (field: string) =>
  z
    .string()
    .trim()
    .refine(
      (raw) => parseAddresses(raw).every((a) => z.email().safeParse(a).success),
      `One of the ${field} addresses is not a valid email.`,
    )
    .refine(
      (raw) => parseAddresses(raw).length <= MAX_RECIPIENTS,
      `Up to ${MAX_RECIPIENTS} ${field} addresses.`,
    );

const schema = z.object({
  to: z.email("Enter a valid recipient email address."),
  cc: addressList("Cc"),
  bcc: addressList("Bcc"),
  reply_to: z
    .string()
    .trim()
    .refine(
      (v) => v === "" || z.email().safeParse(v).success,
      "Enter a valid reply-to email address.",
    ),
  subject: z.string().trim().min(1, "Give the email a subject.").max(255),
  // The editor emits "<p></p>" for an empty document, so a length check on the HTML
  // would pass an email with nothing in it.
  body_html: z
    .string()
    .refine(
      (html) => html.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim() !== "",
      "Write something in the message before sending.",
    ),
  deadline: z.string(),
});

type FormValues = z.infer<typeof schema>;

/**
 * Compose-on-send for every candidate email on the recruitment board.
 *
 * The board used to fire four of the five stage emails straight from a hardcoded template
 * on one click — HR could not change a word, correct a wrong address, copy the hiring
 * manager, or attach anything. This is that click's new destination: the server supplies
 * the stage template as a starting point, and everything in it is editable before it goes.
 *
 * What was sent is recorded, so reopening this for a type already sent starts from the last
 * message rather than reverting to boilerplate.
 */
export function CandidateEmailDialog({
  candidate,
  type,
  open,
  onOpenChange,
}: {
  candidate: Candidate;
  /** Which stage email this is — decides the template and what the board records. */
  type: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const stage = STAGE_EMAIL[candidate.stage ?? ""];
  const title = stage?.title ?? "Email";
  const isAssessment = type === "assessment";

  const draftQuery = useCandidateEmailDraft(open ? candidate.id : undefined, type);
  const sendMut = useSendCandidateEmail();

  const [attachments, setAttachments] = useState<File[]>([]);
  const [attachmentError, setAttachmentError] = useState<string | null>(null);
  // Only ever set by the "Cc / Bcc" button. Whether the rows are ALREADY open is derived
  // from the draft below, so a previous send's recipients are never hidden.
  const [moreRequested, setMoreRequested] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      to: "",
      cc: "",
      bcc: "",
      reply_to: "",
      subject: "",
      body_html: "",
      deadline: "",
    },
  });

  const draft = draftQuery.data;

  // The draft arrives after the dialog opens, so the form is filled rather than mounted
  // with its content. Keyed on the draft object: refetching a fresh one refills.
  useEffect(() => {
    if (!draft) return;
    reset({
      to: draft.to,
      cc: draft.cc.join(", "),
      bcc: draft.bcc.join(", "),
      reply_to: draft.reply_to ?? "",
      subject: draft.subject,
      body_html: draft.body_html,
      deadline: candidate.assessment_deadline ?? "",
    });
  }, [draft, reset, candidate.assessment_deadline]);

  // Anything a previous send carried must be on screen, not hidden behind a toggle —
  // derived rather than stored, so it can't fall out of step with the draft.
  const showMore =
    moreRequested ||
    (!!draft && (draft.cc.length > 0 || draft.bcc.length > 0 || !!draft.reply_to));

  // A closed dialog keeps nothing: attachments are files picked for one message.
  function handleOpenChange(next: boolean): void {
    if (!next) {
      setAttachments([]);
      setAttachmentError(null);
      setMoreRequested(false);
    }
    onOpenChange(next);
  }

  const totalBytes = useMemo(
    () => attachments.reduce((sum, f) => sum + f.size, 0),
    [attachments],
  );

  function onPickFiles(picked: FileList | null): void {
    if (!picked?.length) return;
    const incoming = Array.from(picked);

    const tooBig = incoming.find((f) => f.size > MAX_FILE_BYTES);
    if (tooBig) {
      setAttachmentError(`"${tooBig.name}" is larger than 5 MB.`);
      return;
    }
    if (attachments.length + incoming.length > MAX_FILES) {
      setAttachmentError(`Attach up to ${MAX_FILES} files.`);
      return;
    }

    setAttachmentError(null);
    setAttachments((current) => [...current, ...incoming]);
  }

  function onSubmit(v: FormValues): void {
    sendMut.mutate(
      {
        id: candidate.id,
        type,
        to: v.to.trim(),
        cc: parseAddresses(v.cc),
        bcc: parseAddresses(v.bcc),
        reply_to: v.reply_to.trim() || null,
        subject: v.subject.trim(),
        body_html: v.body_html,
        deadline: isAssessment ? v.deadline || null : null,
        attachments,
      },
      {
        onSuccess: () => {
          toast.success(
            draft?.previous_sent_at ? "Email re-sent" : "Email sent",
            `${candidate.name} has it at ${v.to.trim()}.`,
          );
          handleOpenChange(false);
        },
        onError: (err) => {
          if (err instanceof AxiosError) {
            if (err.response?.status === 413) {
              setAttachmentError(
                "The attachments are too large to upload. Try smaller files.",
              );
              return;
            }
            if (err.response?.status === 422) {
              const serverErrors: Record<string, string[]> = err.response.data?.errors ?? {};
              let shown = false;
              for (const field of Object.keys(serverErrors)) {
                const message = serverErrors[field][0];
                if (field in schema.shape) {
                  setError(field as keyof FormValues, { message });
                  shown = true;
                } else if (field.startsWith("attachments")) {
                  setAttachmentError(message);
                  shown = true;
                } else {
                  toast.error(message);
                  shown = true;
                }
              }
              if (shown) return;
            }
          }
          toast.error("Could not send the email. Please try again.");
        },
      },
    );
  }

  const loading = draftQuery.isLoading;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="flex max-h-[92svh] max-w-[calc(100%-2rem)] flex-col overflow-hidden sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span className="flex size-8 items-center justify-center rounded-lg bg-brand-subtle text-brand-foreground">
              <Mail className="size-4" />
            </span>
            {title}
          </DialogTitle>
          <DialogDescription>
            {draft?.previous_sent_at
              ? `Last sent ${formatDateTime(draft.previous_sent_at)} — this starts from that message.`
              : `To ${candidate.name}. Edit anything before sending.`}
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="space-y-3 py-6" aria-busy="true">
            <div className="h-9 animate-pulse rounded-lg bg-black/5" />
            <div className="h-9 animate-pulse rounded-lg bg-black/5" />
            <div className="h-48 animate-pulse rounded-lg bg-black/5" />
          </div>
        ) : draftQuery.isError ? (
          <p className="py-6 text-sm text-destructive">
            Could not load the email template. Close this and try again.
          </p>
        ) : (
          <form
            onSubmit={handleSubmit(onSubmit)}
            className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto"
            noValidate
          >
            {/* Recipients — the header block, laid out like a mail client rather than
                a stack of labelled fields. */}
            <div className="rounded-lg border border-line">
              <HeaderRow label="To" htmlFor="email-to" invalid={!!errors.to}>
                <input
                  id="email-to"
                  type="email"
                  autoComplete="off"
                  className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                  {...register("to")}
                />
                {!showMore && (
                  <button
                    type="button"
                    onClick={() => setMoreRequested(true)}
                    className="shrink-0 text-caption font-medium text-muted-foreground hover:text-foreground"
                  >
                    Cc / Bcc
                  </button>
                )}
              </HeaderRow>

              {showMore && (
                <>
                  <HeaderRow label="Cc" htmlFor="email-cc" invalid={!!errors.cc}>
                    <input
                      id="email-cc"
                      className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                      placeholder="Separate addresses with commas"
                      {...register("cc")}
                    />
                  </HeaderRow>
                  <HeaderRow label="Bcc" htmlFor="email-bcc" invalid={!!errors.bcc}>
                    <input
                      id="email-bcc"
                      className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                      placeholder="Separate addresses with commas"
                      {...register("bcc")}
                    />
                  </HeaderRow>
                  <HeaderRow
                    label="Reply-to"
                    htmlFor="email-reply-to"
                    invalid={!!errors.reply_to}
                  >
                    <input
                      id="email-reply-to"
                      type="email"
                      className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                      placeholder="Where replies should go"
                      {...register("reply_to")}
                    />
                  </HeaderRow>
                </>
              )}

              <HeaderRow label="Subject" htmlFor="email-subject" invalid={!!errors.subject} last>
                <input
                  id="email-subject"
                  className="w-full bg-transparent text-sm outline-none placeholder:text-ink-faint"
                  {...register("subject")}
                />
              </HeaderRow>
            </div>

            <FieldErrors
              messages={[
                errors.to?.message,
                errors.cc?.message,
                errors.bcc?.message,
                errors.reply_to?.message,
                errors.subject?.message,
              ]}
            />

            {isAssessment && (
              <div className="flex flex-wrap items-center gap-3">
                <Label htmlFor="assessment-deadline" className="text-caption">
                  Deadline (optional)
                </Label>
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
                <p className="text-caption text-muted-foreground">
                  Shown on the candidate&rsquo;s card. Mention it in the message too.
                </p>
              </div>
            )}

            <Controller
              control={control}
              name="body_html"
              render={({ field }) => (
                <RichTextEditor
                  value={field.value}
                  onChange={field.onChange}
                  ariaLabel="Email message"
                  invalid={!!errors.body_html}
                  editorClassName="min-h-52 max-h-[38svh]"
                />
              )}
            />
            {errors.body_html && (
              <p className="text-xs text-destructive">{errors.body_html.message}</p>
            )}

            {/* Attachments */}
            <div className="space-y-2">
              <input
                ref={fileInput}
                type="file"
                multiple
                className="hidden"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.csv,.zip"
                onChange={(e) => {
                  onPickFiles(e.target.files);
                  // Let the same file be re-picked after removing it.
                  e.target.value = "";
                }}
              />
              <Button
                type="button"
                size="xs"
                variant="outline"
                onClick={() => fileInput.current?.click()}
                disabled={attachments.length >= MAX_FILES}
              >
                <Paperclip />
                Attach files
              </Button>

              {attachments.length > 0 && (
                <ul className="space-y-1">
                  {attachments.map((file, index) => (
                    <li
                      key={`${file.name}-${index}`}
                      className="flex items-center gap-2 rounded-md border border-line px-2 py-1"
                    >
                      <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate text-caption">{file.name}</span>
                      <span className="shrink-0 text-caption text-muted-foreground">
                        {formatBytes(file.size)}
                      </span>
                      <button
                        type="button"
                        aria-label={`Remove ${file.name}`}
                        onClick={() =>
                          setAttachments((current) =>
                            current.filter((_, i) => i !== index),
                          )
                        }
                        className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-black/5 hover:text-foreground"
                      >
                        <X className="size-3.5" />
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              {attachments.length > 0 && (
                <p className="text-caption text-muted-foreground">
                  {attachments.length} of {MAX_FILES} files · {formatBytes(totalBytes)}
                </p>
              )}
              {attachmentError && (
                <p className="text-xs text-destructive">{attachmentError}</p>
              )}
            </div>

            <DialogFooter className="mt-1">
              <DialogClose asChild>
                <Button type="button" variant="outline">
                  Cancel
                </Button>
              </DialogClose>
              <Button type="submit" disabled={sendMut.isPending}>
                <Mail />
                {sendMut.isPending
                  ? "Sending…"
                  : draft?.previous_sent_at
                    ? "Re-send email"
                    : "Send email"}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

/** One labelled row of the header block — the "To:" / "Subject:" lines. */
function HeaderRow({
  label,
  htmlFor,
  invalid,
  last = false,
  children,
}: {
  label: string;
  htmlFor: string;
  invalid?: boolean;
  last?: boolean;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <div
      className={cn(
        "flex items-center gap-2 px-3 py-2",
        !last && "border-b border-line",
        invalid && "bg-destructive/5",
      )}
    >
      <Label
        htmlFor={htmlFor}
        className="w-16 shrink-0 text-caption font-medium text-muted-foreground"
      >
        {label}
      </Label>
      {children}
    </div>
  );
}

/** The header block has no room for per-field errors, so they collect underneath it. */
function FieldErrors({
  messages,
}: {
  messages: Array<string | undefined>;
}): React.ReactElement | null {
  const shown = messages.filter(Boolean);
  if (shown.length === 0) return null;

  return (
    <div className="space-y-0.5">
      {shown.map((message) => (
        <p key={message} className="text-xs text-destructive">
          {message}
        </p>
      ))}
    </div>
  );
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
