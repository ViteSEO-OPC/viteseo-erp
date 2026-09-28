import { renditionSize, type CropRect } from "@/modules/crm/features/email-templates/lib/logo-crop";

/**
 * The PNG email clients will receive for a cropped SVG logo, drawn in the browser.
 *
 * The server keeps the SVG as vector but has no SVG rasterizer, and email clients do not render SVG reliably, so
 * the PNG is drawn here — from the SERVER-SANITIZED SVG the inspect step returned, never from the uploaded file.
 * The SVG is parsed as XML in a detached document (nothing is inserted into the page, and SVG loaded as an image
 * runs no script and fetches nothing), cropped the same way the server crops it — by setting its viewBox to the
 * crop — sized to the rendition, and drawn onto a transparent canvas. Drawing the cropped vector at full size keeps
 * it sharp however tight the crop is. The server re-encodes the PNG and checks it matches the crop's shape.
 */
export async function rasterizeCroppedSvg(sanitizedSvg: string, crop: CropRect): Promise<Blob> {
  const document_ = new DOMParser().parseFromString(sanitizedSvg, "image/svg+xml");
  const root = document_.documentElement;
  if (root.localName !== "svg" || document_.getElementsByTagName("parsererror").length > 0) {
    throw new Error("The logo could not be prepared for email clients.");
  }

  const [viewX = 0, viewY = 0] = (root.getAttribute("viewBox") ?? "")
    .trim()
    .split(/[\s,]+/)
    .map(Number);
  const size = renditionSize(crop);

  root.setAttribute("viewBox", `${viewX + crop.x} ${viewY + crop.y} ${crop.width} ${crop.height}`);
  root.setAttribute("width", String(size.width));
  root.setAttribute("height", String(size.height));
  root.setAttribute("preserveAspectRatio", "xMidYMid meet");

  const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(document_)], { type: "image/svg+xml" }));
  try {
    const image = new Image();
    image.src = url;
    await image.decode();

    const canvas = document.createElement("canvas");
    canvas.width = size.width;
    canvas.height = size.height;
    const context = canvas.getContext("2d");
    if (!context) throw new Error("The logo could not be prepared for email clients.");
    context.clearRect(0, 0, size.width, size.height);
    context.drawImage(image, 0, 0, size.width, size.height);

    return await new Promise<Blob>((resolve, reject) =>
      canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("The logo could not be prepared for email clients."))), "image/png"),
    );
  } finally {
    URL.revokeObjectURL(url);
  }
}
