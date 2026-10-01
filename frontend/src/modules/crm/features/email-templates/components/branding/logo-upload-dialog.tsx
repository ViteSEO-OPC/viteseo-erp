"use client";

import { useEffect, useRef, useState } from "react";
import { AlertTriangle, Loader2, ShieldCheck, Upload } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/modules/crm/components/ui/dialog";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { cn } from "@/modules/crm/lib/utils";
import { CHECKERBOARD, LogoCropper } from "@/modules/crm/features/email-templates/components/branding/logo-cropper";
import { useInspectLogo, useUploadLogo } from "@/modules/crm/features/email-templates/hooks/use-email-branding";
import { apiFieldErrors } from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import {
  aspectProblem,
  describeRemoved,
  emailImageSize,
  fileProblem,
  formatFileSize,
  fullCrop,
  minCropSize,
  normalizeCrop,
  type CropRect,
} from "@/modules/crm/features/email-templates/lib/logo-crop";
import { rasterizeCroppedSvg } from "@/modules/crm/features/email-templates/lib/svg-rendition";
import type { LogoInspection } from "@/modules/crm/features/email-templates/types/email-design-types";

/** The logo block's default width, so the live preview shows the logo at the size designs start with. */
const PREVIEW_WIDTH = 160;
const PREVIEW_MAX_HEIGHT = 200;

type Stage = {
  file: File;
  inspection: LogoInspection;
  /** A blob: URL — of the file for a PNG, of the SERVER-SANITIZED SVG for an SVG. */
  src: string;
};

/**
 * Upload, crop and save the organization logo.
 *
 * 1. Choose a PNG or SVG. The server checks it and stores nothing; an SVG comes back sanitized, with what was
 *    removed, and only that sanitized SVG is shown, cropped and drawn from here on.
 * 2. Crop and position it. The preview beside the cropper shows the result immediately, at the logo block's
 *    default width, over a transparency checkerboard.
 * 3. Save. A PNG goes up with the crop and the server crops and re-encodes it. An SVG goes up with the crop, which
 *    the server applies to the vector, and the PNG email clients will receive, drawn here from the cropped SVG.
 */
export function LogoUploadDialog({ onClose, replacing }: { onClose: () => void; replacing: boolean }) {
  const inspect = useInspectLogo();
  const upload = useUploadLogo();
  const inputRef = useRef<HTMLInputElement>(null);

  const [stage, setStage] = useState<Stage | null>(null);
  const [crop, setCrop] = useState<CropRect | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rendering, setRendering] = useState(false);

  useEffect(() => {
    if (!stage) return;
    return () => URL.revokeObjectURL(stage.src);
  }, [stage]);

  const busy = inspect.isPending || upload.isPending || rendering;

  async function choose(file: File | undefined) {
    if (!file) return;
    setError(null);
    const problem = fileProblem(file.name, file.size);
    if (problem) {
      setError(problem);
      return;
    }

    try {
      const inspection = await inspect.mutateAsync(file);
      const src =
        inspection.format === "svg"
          ? URL.createObjectURL(new Blob([inspection.svg ?? ""], { type: "image/svg+xml" }))
          : URL.createObjectURL(file);
      setStage({ file, inspection, src });
      setCrop(fullCrop(inspection.width, inspection.height));
    } catch (err) {
      setError(apiFieldErrors(err).file ?? getApiErrorMessage(err, "This logo could not be read."));
    } finally {
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  async function save() {
    if (!stage || !crop) return;
    const { inspection } = stage;
    const final = normalizeCrop(crop, inspection.format, inspection);
    setError(null);

    let rendition: Blob | undefined;
    if (inspection.format === "svg") {
      setRendering(true);
      try {
        rendition = await rasterizeCroppedSvg(inspection.svg ?? "", final);
      } catch (err) {
        setError(err instanceof Error ? err.message : "The logo could not be prepared for email clients.");
        return;
      } finally {
        setRendering(false);
      }
    }

    upload.mutate(
      { file: stage.file, crop: final, rendition },
      {
        onSuccess: onClose,
        onError: (err) => {
          const fields = apiFieldErrors(err);
          setError(fields.file ?? fields.crop ?? fields.rendition ?? getApiErrorMessage(err, "Could not save the logo."));
        },
      },
    );
  }

  const inspection = stage?.inspection ?? null;
  const shapeProblem = crop ? aspectProblem(crop) : null;
  const output = crop && inspection ? emailImageSize(normalizeCrop(crop, inspection.format, inspection), inspection.format) : null;
  const previewScale = crop ? Math.min(PREVIEW_WIDTH / crop.width, PREVIEW_MAX_HEIGHT / crop.height) : 1;
  const removed = inspection?.removed ?? {};

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{replacing ? "Replace organization logo" : "Upload organization logo"}</DialogTitle>
          <DialogDescription>
            PNG or SVG, up to 2 MB. Every design&apos;s Logo block shows this logo, so saving it updates all designs and
            templates at once.
          </DialogDescription>
        </DialogHeader>

        <input
          ref={inputRef}
          type="file"
          accept=".png,.svg,image/png,image/svg+xml"
          aria-label="Logo file"
          className="sr-only"
          onChange={(event) => void choose(event.target.files?.[0])}
          disabled={busy}
        />

        {error ? (
          <p role="alert" className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
            {error}
          </p>
        ) : null}

        {!stage || !crop || !inspection ? (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            disabled={busy}
            className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border px-6 py-10 text-sm text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
          >
            {inspect.isPending ? <Loader2 className="size-5 animate-spin" aria-hidden /> : <Upload className="size-5" aria-hidden />}
            {inspect.isPending ? "Checking the file…" : "Choose a PNG or SVG logo"}
          </button>
        ) : (
          <div className="grid gap-4 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div className="flex min-w-0 flex-col gap-2">
              <p className="text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">Crop and position</p>
              <LogoCropper
                src={stage.src}
                alt="Logo being cropped"
                bounds={inspection}
                crop={crop}
                minSize={minCropSize(inspection.format, inspection)}
                onChange={setCrop}
                disabled={busy}
              />
              <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>
                  {stage.file.name} · {formatFileSize(stage.file.size)}
                </span>
                <Button type="button" size="xs" variant="ghost" onClick={() => setCrop(fullCrop(inspection.width, inspection.height))} disabled={busy}>
                  Reset crop
                </Button>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              <p className="text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">Preview</p>
              <div className="flex min-h-32 items-center justify-center rounded-lg border border-border bg-white p-4">
                <div
                  aria-label="Cropped logo preview"
                  role="img"
                  data-logo-preview=""
                  className={cn("relative overflow-hidden", CHECKERBOARD)}
                  style={{ width: crop.width * previewScale, height: crop.height * previewScale }}
                >
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={stage.src}
                    alt=""
                    draggable={false}
                    className="pointer-events-none absolute max-w-none"
                    style={{
                      width: inspection.width * previewScale,
                      height: inspection.height * previewScale,
                      left: -crop.x * previewScale,
                      top: -crop.y * previewScale,
                    }}
                  />
                </div>
              </div>

              <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt className="text-muted-foreground">Kept as</dt>
                <dd className="text-foreground">{inspection.format === "svg" ? "SVG — cropped, still vector" : "PNG — re-encoded"}</dd>
                <dt className="text-muted-foreground">Email image</dt>
                <dd className="text-foreground" data-email-image-size="">
                  {output ? `${output.width} × ${output.height} px PNG` : "—"}
                </dd>
              </dl>

              {inspection.format === "svg" ? (
                <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                  <ShieldCheck className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                  <span>
                    Checked and cleaned by the server.{" "}
                    {Object.keys(removed).length > 0 ? (
                      <strong className="font-medium text-foreground" data-sanitizer-report="">
                        Removed for safety: {describeRemoved(removed)}.
                      </strong>
                    ) : (
                      "Nothing unsafe was found."
                    )}{" "}
                    Email clients receive a PNG, because most do not show SVG.
                  </span>
                </p>
              ) : null}

              {shapeProblem ? (
                <p role="alert" className="text-xs text-red-500">
                  {shapeProblem}
                </p>
              ) : null}
            </div>
          </div>
        )}

        <DialogFooter className="flex-row flex-wrap justify-end gap-2">
          {stage ? (
            <Button type="button" variant="ghost" className="mr-auto" onClick={() => inputRef.current?.click()} disabled={busy}>
              Choose another file
            </Button>
          ) : null}
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button type="button" onClick={() => void save()} disabled={!stage || !crop || shapeProblem !== null || busy}>
            {busy && stage ? <Loader2 className="animate-spin" aria-hidden /> : null}
            {upload.isPending || rendering ? "Saving…" : "Save logo"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
