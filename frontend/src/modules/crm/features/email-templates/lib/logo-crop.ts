/**
 * Pure helpers for cropping the organization logo before it is uploaded: the limits the server enforces, crop
 * rectangle arithmetic for the cropper, and the size of the image email clients will receive. Free of React and
 * the DOM so scripts/email-template-gates can drive them.
 *
 * Coordinates are in the image's own units, from its top-left corner: pixels for a PNG, and for an SVG the user
 * units of the viewBox the server's sanitizer returned. The server crops from the original with exactly these
 * numbers — a PNG with GD, an SVG by rewriting its viewBox — and re-checks every limit here.
 *
 * The constants mirror LogoPng and SvgSanitizer (PHP); the gates read those files and fail on drift.
 */

export type LogoFormat = "png" | "svg";

export type CropRect = { x: number; y: number; width: number; height: number };

export type CropBounds = { width: number; height: number };

export type CropHandle = "n" | "s" | "e" | "w" | "ne" | "nw" | "se" | "sw";

/** LogoPng::MAX_BYTES — also PHP's upload limit. */
export const LOGO_MAX_BYTES = 2_097_152;

/** SvgSanitizer::MAX_BYTES. */
export const SVG_MAX_BYTES = 512_000;

/** LogoPng::RENDITION_WIDTH — twice the widest a logo block is placed. */
export const RENDITION_WIDTH = 600;

/** LogoPng::MIN_CROP, in PNG pixels. */
export const MIN_PNG_CROP = 8;

/** LogoPng::MIN_ASPECT and MAX_ASPECT: a crop's width ÷ height. */
export const MIN_ASPECT = 0.25;

export const MAX_ASPECT = 10;

/** LogoPng::BAD_ASPECT, word for word. */
export const ASPECT_MESSAGE = "Crop the logo to a shape between 1:4 (tall) and 10:1 (wide) so it fits an email header.";

/** The format a file name promises. The server decides from the bytes; this only picks the right flow and copy. */
export function logoFormatOf(fileName: string): LogoFormat | null {
  const name = fileName.toLowerCase();
  if (name.endsWith(".png")) return "png";
  if (name.endsWith(".svg")) return "svg";
  return null;
}

/** Why a file cannot be used, before anything is sent — or null. Mirrors UploadEmailLogoRequest's copy. */
export function fileProblem(fileName: string, bytes: number): string | null {
  const format = logoFormatOf(fileName);
  if (format === null) return "Upload a PNG or SVG logo.";
  if (bytes > LOGO_MAX_BYTES) return "The logo must be 2 MB or smaller.";
  if (format === "svg" && bytes > SVG_MAX_BYTES) return "The SVG must be 500 KB or smaller.";
  return null;
}

/** The whole image. */
export function fullCrop(width: number, height: number): CropRect {
  return { x: 0, y: 0, width, height };
}

/** The smallest crop the cropper allows: MIN_PNG_CROP pixels, or 1% of an SVG's longer side. */
export function minCropSize(format: LogoFormat, bounds: CropBounds): CropBounds {
  if (format === "png") {
    return { width: Math.min(MIN_PNG_CROP, bounds.width), height: Math.min(MIN_PNG_CROP, bounds.height) };
  }
  const side = Math.max(bounds.width, bounds.height) / 100;
  return { width: Math.min(side, bounds.width), height: Math.min(side, bounds.height) };
}

const clamp = (value: number, min: number, max: number) => Math.min(Math.max(value, min), max);

/** The crop moved by (dx, dy), kept inside the image. */
export function moveCrop(rect: CropRect, dx: number, dy: number, bounds: CropBounds): CropRect {
  return {
    ...rect,
    x: clamp(rect.x + dx, 0, bounds.width - rect.width),
    y: clamp(rect.y + dy, 0, bounds.height - rect.height),
  };
}

/** The crop with one edge or corner dragged by (dx, dy): never past the image, never smaller than `min`. */
export function resizeCrop(rect: CropRect, handle: CropHandle, dx: number, dy: number, bounds: CropBounds, min: CropBounds): CropRect {
  let left = rect.x;
  let top = rect.y;
  let right = rect.x + rect.width;
  let bottom = rect.y + rect.height;

  if (handle.includes("w")) left = clamp(left + dx, 0, right - min.width);
  if (handle.includes("e")) right = clamp(right + dx, left + min.width, bounds.width);
  if (handle.includes("n")) top = clamp(top + dy, 0, bottom - min.height);
  if (handle.includes("s")) bottom = clamp(bottom + dy, top + min.height, bounds.height);

  return { x: left, y: top, width: right - left, height: bottom - top };
}

/** Null when the crop's shape suits an email header; otherwise the server's message. */
export function aspectProblem(rect: CropRect): string | null {
  if (rect.width <= 0 || rect.height <= 0) return ASPECT_MESSAGE;
  const aspect = rect.width / rect.height;
  return aspect < MIN_ASPECT || aspect > MAX_ASPECT ? ASPECT_MESSAGE : null;
}

const round4 = (value: number) => Math.round(value * 10_000) / 10_000;

/** What is sent: whole pixels for a PNG, four decimals for an SVG — always inside the image. */
export function normalizeCrop(rect: CropRect, format: LogoFormat, bounds: CropBounds): CropRect {
  const round = format === "png" ? Math.round : round4;
  const x = clamp(round(rect.x), 0, bounds.width);
  const y = clamp(round(rect.y), 0, bounds.height);
  return {
    x,
    y,
    width: clamp(round(rect.width), 0, round(bounds.width - x)),
    height: clamp(round(rect.height), 0, round(bounds.height - y)),
  };
}

/** The PNG the browser draws from a cropped SVG: RENDITION_WIDTH wide, at the crop's shape. */
export function renditionSize(rect: CropRect): CropBounds {
  return { width: RENDITION_WIDTH, height: Math.max(1, Math.round((RENDITION_WIDTH * rect.height) / rect.width)) };
}

/** The PNG the server keeps from a cropped PNG: at most RENDITION_WIDTH wide, never enlarged. */
export function pngOutputSize(rect: CropRect): CropBounds {
  const width = Math.min(Math.round(rect.width), RENDITION_WIDTH);
  return { width, height: Math.max(1, Math.round((width * rect.height) / rect.width)) };
}

/** The size email clients receive for this crop. */
export function emailImageSize(rect: CropRect, format: LogoFormat): CropBounds {
  return format === "svg" ? renditionSize(rect) : pngOutputSize(rect);
}

/** "1.2 MB", "340 KB". */
export function formatFileSize(bytes: number): string {
  return bytes >= 1_000_000 ? `${(bytes / 1_000_000).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1000))} KB`;
}

/** "1 script, 2 event handlers" — what the SVG sanitizer removed. */
export function describeRemoved(removed: Record<string, number>): string {
  return Object.entries(removed)
    .map(([what, count]) => `${count} ${what}${count === 1 ? "" : what.endsWith("s") ? "es" : "s"}`)
    .join(", ");
}
