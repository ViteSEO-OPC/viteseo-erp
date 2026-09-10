"use client";

import { useRef } from "react";
import { Paperclip, Download, Upload } from "lucide-react";

import { RowList, Row, SectionHeading } from "@/core/components/editorial";
import {
  useAttachments,
  useUploadAttachment,
  downloadAttachment,
} from "@/modules/accounting/hooks/useAttachments";
import { useAccountingPerms } from "@/modules/accounting/permissions";

export function AttachmentsPanel({
  type,
  id,
}: {
  type: "invoice" | "bill";
  id: number;
}) {
  const { data: files } = useAttachments(type, id);
  const upload = useUploadAttachment(type, id);
  const { canManage } = useAccountingPerms();
  const inputRef = useRef<HTMLInputElement>(null);

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      upload.mutate(file, {
        onSuccess: () => {
          if (inputRef.current) inputRef.current.value = "";
        },
      });
    }
  };

  return (
    <div className="rounded-card border border-border bg-card p-6">
      <div className="flex items-end justify-between gap-3">
        <SectionHeading
          title={
            <span className="flex items-center gap-2">
              <Paperclip className="size-4" /> Attachments
            </span>
          }
          meta={`${(files ?? []).length} file${(files ?? []).length === 1 ? "" : "s"}`}
        />
        {canManage ? (
          <>
            <button
              type="button"
              onClick={() => inputRef.current?.click()}
              disabled={upload.isPending}
              className="inline-flex items-center gap-1.5 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              <Upload className="size-4" /> {upload.isPending ? "Uploading…" : "Upload"}
            </button>
            <input
              ref={inputRef}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              onChange={onPick}
              className="hidden"
            />
          </>
        ) : null}
      </div>

      <div className="mt-4">
        {(files ?? []).length === 0 ? (
          <p className="text-sm text-muted-foreground">No attachments yet.</p>
        ) : (
          <RowList>
            {files!.map((f, i) => (
              <Row key={f.id} index={i}>
                <div className="flex items-center justify-between gap-3 text-sm">
                  <span className="min-w-0 truncate text-foreground">
                    {f.original_name}{" "}
                    <span className="text-caption text-muted-foreground">
                      ({Math.max(1, Math.round(f.size / 1024))} KB)
                    </span>
                  </span>
                  <button
                    type="button"
                    onClick={() => downloadAttachment(f)}
                    className="shrink-0 text-brand transition-opacity hover:opacity-80"
                    aria-label={`Download ${f.original_name}`}
                  >
                    <Download className="size-4" />
                  </button>
                </div>
              </Row>
            ))}
          </RowList>
        )}
      </div>

      {upload.isError ? (
        <p className="mt-2 text-caption text-destructive">
          Upload failed — only PDF/JPG/PNG up to 5 MB.
        </p>
      ) : null}
    </div>
  );
}
