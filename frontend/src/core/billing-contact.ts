/**
 * How customers reach Vite SEO to pay / reactivate. Shown on the paywall and
 * trial banner. Edit these with the real details (and add QR image data URIs
 * under `qr` when ready — e.g. GCash / bank QR).
 */
export const BILLING_CONTACT = {
  email: "billing@viteseo.ph",
  phone: "+63 900 000 0000",
  // Short, human steps a customer follows to pay.
  steps: [
    "Message us at the email or number below with your organization name.",
    "We’ll confirm your plan and send payment instructions.",
    "Once payment is received, we reactivate your access right away.",
  ],
  // Accepted methods (display only). Fill in real handles.
  methods: [
    { label: "GCash", detail: "0900 000 0000 (Vite SEO)" },
    { label: "Bank transfer", detail: "BDO • 0000-0000-0000 • Vite SEO Digital Advertising OPC" },
  ],
  // Optional: data-URI images (e.g. "data:image/png;base64,...") for QR codes.
  // Leave empty until you have them; the UI hides this section when empty.
  qr: [] as { label: string; src: string }[],
};
