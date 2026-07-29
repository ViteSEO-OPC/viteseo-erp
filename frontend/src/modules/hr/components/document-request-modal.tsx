"use client";

import { useEffect, useState } from "react";
import { Download } from "lucide-react";
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
import { StatusBadge } from "@/core/components/status-badge";
import { DASH, RequestDetailDialog } from "@/modules/hr/components/request-detail-dialog";
import { formatDate } from "@/core/lib/format";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DOCUMENT_TYPES, documentTypeLabel } from "@/modules/hr/types/request";
import {
  useCreateDocumentRequest,
  useMyDocumentRequests,
} from "@/modules/hr/hooks/use-requests";

/** Employee-facing wording for the request lifecycle (mirrors the HR queue). */
const DOC_STATUS_LABEL: Record<string, string> = {
  pending: "Pending",
  in_progress: "Processing",
  fulfilled: "Completed",
  rejected: "Rejected",
};

/** Employee self-service: request an official document from HR + see the history. */
export function DocumentRequestModal({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const createMut = useCreateDocumentRequest();
  const { data: history } = useMyDocumentRequests();
  const [type, setType] = useState<string>(DOCUMENT_TYPES[0].value);
  const [otherDetail, setOtherDetail] = useState("");
  const [reason, setReason] = useState("");
  // A past request opened from the history list, stacked over this dialog.
  const [detailId, setDetailId] = useState<number | null>(null);
  const detail = (history ?? []).find((d) => d.id === detailId) ?? null;

  const isOther = type === "other";

  useEffect(() => {
    if (open) {
      setType(DOCUMENT_TYPES[0].value);
      setOtherDetail("");
      setReason("");
    }
  }, [open]);

  const canSubmit = !isOther || otherDetail.trim() !== "";

  async function submit() {
    if (!canSubmit) return;
    try {
      await createMut.mutateAsync({
        type,
        other_detail: isOther ? otherDetail.trim() : null,
        reason: reason.trim() || null,
      });
      toast.success("Document request submitted", "HR will process it.");
      setOtherDetail("");
      setReason("");
    } catch {
      toast.error("Could not submit the request.");
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Request a document</DialogTitle>
          <DialogDescription>
            Ask HR for an official document. You can download it here once it&apos;s ready.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="doc-type">Document</Label>
              <SimpleSelect
                id="doc-type"
                value={type}
                onValueChange={setType}
                options={DOCUMENT_TYPES.map((t) => ({ value: t.value, label: t.label }))}
              />
            </div>
            {isOther ? (
              <div className="space-y-1.5">
                <Label htmlFor="doc-other">Specify document</Label>
                <Input
                  id="doc-other"
                  value={otherDetail}
                  onChange={(e) => setOtherDetail(e.target.value)}
                  placeholder="e.g. BIR 2316 form"
                />
              </div>
            ) : (
              <div className="space-y-1.5">
                <Label htmlFor="doc-reason">Reason (optional)</Label>
                <Input
                  id="doc-reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="e.g. for a loan application"
                />
              </div>
            )}
          </div>
          {isOther && (
            <div className="space-y-1.5">
              <Label htmlFor="doc-reason-other">Reason (optional)</Label>
              <Input
                id="doc-reason-other"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="e.g. for a new employer"
              />
            </div>
          )}
          <Button size="sm" onClick={submit} disabled={createMut.isPending || !canSubmit}>
            {createMut.isPending ? "Submitting…" : "Submit request"}
          </Button>

          {/* History */}
          <div className="border-t border-border pt-3">
            <p className="mb-2 text-label font-bold uppercase tracking-wide text-muted-foreground">
              Request history
            </p>
            {(history ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No document requests yet.</p>
            ) : (
              <div className="max-h-[40vh] space-y-2 overflow-y-auto">
                {(history ?? []).map((d) => (
                  <div
                    key={d.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => setDetailId(d.id)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        setDetailId(d.id);
                      }
                    }}
                    className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-1 py-1 text-sm transition-colors hover:bg-muted/50"
                  >
                    <div className="min-w-0">
                      <p className="font-medium text-foreground">
                        {d.type === "other" && d.other_detail
                          ? d.other_detail
                          : documentTypeLabel(d.type)}
                      </p>
                      <p className="text-caption text-muted-foreground">{formatDate(d.created_at)}</p>
                    </div>
                    <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                      {d.fulfilled_url && (
                        <Button size="xs" variant="outline" asChild>
                          <a href={d.fulfilled_url} target="_blank" rel="noreferrer">
                            <Download /> File
                          </a>
                        </Button>
                      )}
                      <StatusBadge status={d.status} />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Close</Button>
          </DialogClose>
        </DialogFooter>

        {detail && (
          <RequestDetailDialog
            open
            onOpenChange={(o) => !o && setDetailId(null)}
            title={documentTypeLabel(detail.type)}
            status={detail.status}
            statusLabel={DOC_STATUS_LABEL[detail.status]}
            submitted={detail.created_at}
            fields={[
              { label: "Document", value: documentTypeLabel(detail.type) },
              {
                label: "Which one",
                value: detail.type === "other" ? detail.other_detail || DASH : undefined,
              },
              {
                label: "Your file",
                value: detail.fulfilled_url ? (
                  <a
                    href={detail.fulfilled_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-brand hover:underline"
                  >
                    Download document
                  </a>
                ) : undefined,
              },
            ]}
            note={{ label: "Your reason", text: detail.reason }}
            decision={{
              label: detail.status === "rejected" ? "Why it was declined" : "HR's decision",
              by: detail.reviewed_by,
              at: detail.reviewed_at,
              note: detail.review_note,
            }}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
