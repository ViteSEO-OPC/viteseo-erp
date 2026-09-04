// The Kiosk site builder — turns a selection + resolved theme into a single,
// self-contained HTML document (inline CSS, its own Google Fonts, a vanilla-JS
// animation layer). The SAME output powers the live preview (in an iframe) and
// the "Export to HTML/CSS/JS" download. The theme is decoupled: colour (palette),
// typography (fonts), size (scale), and style (clean/playful) are independent.

export interface KioskTheme {
  palette: { primary: string; accent: string; bg: string; text: string };
  fonts: { heading: string; body: string };
  /** compact | standard | spacious */
  scale: string;
  /** clean | playful */
  style: string;
  /** standard | handwritten */
  headingStyle: string;
  /** sharp | soft | pill */
  radius: string;
  /** none | wipe | flip | gradient | glow | shine — CTA button hover effect */
  ctaHover: string;
  /** true → dark-surface theme (cards/footers/borders adapt) */
  dark: boolean;
}

/** The handwritten font used when headingStyle === "handwritten". */
const HANDWRITTEN_FONT = "Caveat";

/** One content section on a page + its chosen layout variant. */
export interface SiteSection {
  section: string;
  variant: string;
}

/** A single page of the site — its own optional hero + ordered content sections.
 *  All pages share the site-wide header + footer. */
export interface SitePage {
  /** Nav label, e.g. "About". */
  name: string;
  /** URL/anchor slug, e.g. "about" (unique per site). */
  slug: string;
  /** Optional hero variant for this page (Home usually has one; inner pages
   *  often don't). Null = no hero band. */
  hero: string | null;
  sections: SiteSection[];
}

export interface SiteInput {
  businessName: string;
  websiteType: string | null;
  header: string | null;
  footer: string | null;
  /** The site's pages. Rendered as one self-contained document; a top-nav shows
   *  one page at a time (client-side), so the export stays a single file. */
  pages: SitePage[];
  /** Optional prospect logo as a base64 `data:image/...` URI. When set, it
   *  replaces the text wordmark in the header + footer. Baked inline so the
   *  export stays self-contained. */
  logo?: string | null;
  /** Which page opens first (its slug). Defaults to the first page. Used by the
   *  wizard so the preview shows the page currently being edited. */
  activeSlug?: string | null;
}

/** Nav item derived from a page — label + the slug its link targets. */
interface NavItem {
  label: string;
  slug: string;
}

/** Header/footer brand mark: the uploaded logo image when present, else the
 *  business name as a text wordmark. */
function logoMarkup(name: string, logo?: string | null): string {
  return logo
    ? `<img class="logo logo-img" src="${esc(logo)}" alt="${esc(name)}">`
    : `<span class="logo">${esc(name)}</span>`;
}

const TAGLINES: Record<string, string> = {
  informational: "Everything you need to know — all in one place.",
  ecommerce: "Shop our latest picks, delivered to your door.",
  portfolio: "A showcase of selected work & projects.",
  landing: "The simplest way to get started today.",
  booking: "Book your appointment in just a few clicks.",
  blog: "Stories, tips and updates from our team.",
};

/** Type-size scale → CSS custom properties the stylesheet reads. */
const SCALE_VARS: Record<string, string> = {
  compact: "--fs:14px;--h1:34px;--h2:24px;--sec-pad:52px;--hero-pad:82px;--hero-min:40px;",
  standard: "--fs:15px;--h1:44px;--h2:30px;--sec-pad:72px;--hero-pad:118px;--hero-min:52px;",
  spacious: "--fs:17px;--h1:60px;--h2:40px;--sec-pad:98px;--hero-pad:154px;--hero-min:72px;",
};

const esc = (s: string): string =>
  s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

const fontParam = (f: string): string => f.trim().replace(/\s+/g, "+");

function fontsLink(families: string[]): string {
  const fams = Array.from(new Set(families.filter(Boolean)))
    .map((f) => `family=${fontParam(f)}:wght@400;600;700;800`)
    .join("&");
  return `<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?${fams}&display=swap">`;
}

// `data-label` + an inner `.btn-t` span let the "flip" CTA hover swap the label;
// both are inert for the other effects, so every button carries them uniformly.
const btn = (label: string, kind: "solid" | "ghost" = "solid"): string =>
  `<a href="#" class="btn btn--${kind}" data-label="${esc(label)}"><span class="btn-t">${esc(label)}</span></a>`;

// Small uppercase "eyebrow" label above a section title — an editorial detail
// (set per section by pageSection() so section templates need no changes).
const EYEBROWS: Record<string, string> = {
  about: "Who we are",
  services: "What we do",
  products: "Shop",
  gallery: "Our work",
  pricing: "Plans",
  testimonials: "Reviews",
  faq: "Help",
  blog: "Journal",
  team: "Our people",
  contact: "Get in touch",
  stats: "Metrics",
};
let currentEyebrow = "";
const title = (t: string): string =>
  `<div class="sec-head" data-reveal>${currentEyebrow ? `<span class="eyebrow">${esc(currentEyebrow)}</span>` : ""}<h2>${esc(t)}</h2><span class="rule"></span></div>`;

const swatch = (extra = ""): string => `<div class="ph ${extra}"></div>`;

// ---- Header -------------------------------------------------------------
const navLink = (n: NavItem): string =>
  `<a href="#${esc(n.slug)}" data-nav="${esc(n.slug)}">${esc(n.label)}</a>`;

function header(variant: string, name: string, nav: NavItem[], logoSrc?: string | null): string {
  const links = nav.map(navLink).join("");
  const logo = logoMarkup(name, logoSrc);
  const burger = (cls: string) => `<button class="${cls}" aria-label="Menu"><span></span><span></span><span></span></button>`;
  // A collapsible menu the burger toggles (always available on minimal; on mobile
  // for the others).
  const drawer = `<nav class="drawer">${links}</nav>`;
  switch (variant) {
    case "centered":
      return `<header class="site-header hdr--centered"><div class="wrap">${logo}<nav>${links}</nav>${burger("burger burger-m")}</div>${drawer}</header>`;
    case "minimal":
      return `<header class="site-header hdr--minimal"><div class="wrap">${logo}${burger("burger")}</div>${drawer}</header>`;
    case "split":
      return `<header class="site-header hdr--split"><div class="wrap">${logo}<nav>${links}</nav>${btn("Get started")}${burger("burger burger-m")}</div>${drawer}</header>`;
    default:
      return `<header class="site-header hdr--classic"><div class="wrap">${logo}<nav>${links}</nav>${burger("burger burger-m")}</div>${drawer}</header>`;
  }
}

// ---- Hero ---------------------------------------------------------------
function hero(variant: string, name: string, tagline: string): string {
  const h = `Welcome to ${esc(name)}`; // plain (for white-text heroes)
  const hAccent = `Welcome to <span class="hl">${esc(name)}</span>`; // two-tone (light heroes)
  switch (variant) {
    case "split":
      return `<section class="hero hero--split" data-reveal><div class="wrap"><div class="hero-copy"><h1>${hAccent}</h1><p>${esc(tagline)}</p><div class="btns">${btn("Get started")}${btn("Learn more", "ghost")}</div></div><div class="hero-art">${swatch("ph--tall")}</div></div></section>`;
    case "minimal":
      return `<section class="hero hero--minimal" data-reveal><div class="wrap"><h1><span class="hl">${esc(name)}</span></h1><p>${esc(tagline)}</p><span class="rule center"></span></div></section>`;
    case "banner":
      return `<section class="hero hero--banner" data-parallax><div class="overlay"></div><div class="wrap"><h1>${h}</h1><p>${esc(tagline)}</p>${btn("Get started")}</div></section>`;
    case "left":
      return `<section class="hero hero--left" data-reveal><div class="wrap"><h1>${hAccent}</h1><p>${esc(tagline)}</p><div class="btns">${btn("Get started")}${btn("Learn more", "ghost")}</div></div></section>`;
    case "bleed":
      return `<section class="hero hero--bleed" data-reveal><div class="wrap"><div class="hero-copy"><h1>${hAccent}</h1><p>${esc(tagline)}</p><div class="btns">${btn("Get started")}${btn("Learn more", "ghost")}</div></div></div><div class="hero-bleed-art"></div></section>`;
    case "canvas":
      return `<section class="hero hero--canvas"><div class="canvas-lines"></div><div class="canvas-motif" data-depth="16"></div><div class="wrap"><span class="canvas-eyebrow">${esc(tagline)}</span><span class="canvas-mark">${esc(name)}</span></div></section>`;
    case "collage":
      return `<section class="hero hero--collage" data-parallax-mouse><span class="cl-blob b-a" data-depth="24"></span><span class="cl-blob b-b" data-depth="-32"></span><span class="cl-ring" data-depth="16"></span><div class="wrap" data-reveal><h1>${hAccent}</h1><p>${esc(tagline)}</p>${btn("Get started")}</div></section>`;
    case "video":
      return `<section class="hero hero--video"><div class="video-bg"></div><div class="overlay"></div><div class="wrap" data-reveal><span class="vplay" aria-hidden="true">▶</span><h1>${h}</h1><p>${esc(tagline)}</p>${btn("Get started")}</div></section>`;
    default:
      return `<section class="hero hero--image" data-parallax><div class="wrap"><h1>${h}</h1><p>${esc(tagline)}</p>${btn("Get started")}</div></section>`;
  }
}

// ---- Page sections ------------------------------------------------------
function about(variant: string, name: string): string {
  const copy = `<p>We're a proud local business dedicated to quality and service. Learn our story, our mission, and what makes ${esc(name)} different.</p><p>Years of experience, a friendly team, and a commitment to doing things right.</p>`;
  if (variant === "centered") {
    return `<section class="sec about about--centered">${title(`About ${name}`)}<div class="wrap narrow" data-reveal>${copy}</div></section>`;
  }
  if (variant === "stats") {
    const stats: [string, string][] = [["500+", "Happy clients"], ["10", "Years experience"], ["24/7", "Support"], ["100%", "Satisfaction"]];
    const cells = stats.map(([n, l]) => `<div class="stat" data-reveal><div class="num">${n}</div><div class="lbl">${l}</div></div>`).join("");
    return `<section class="sec about">${title(`About ${name}`)}<div class="wrap narrow" data-reveal>${copy}</div><div class="wrap stat-row">${cells}</div></section>`;
  }
  // image_left → image on the left (reverse the default copy/image order).
  const order = variant === "image_left" ? "reverse" : "";
  return `<section class="sec about about--split"><div class="wrap two ${order}" data-reveal><div><h2 class="col-head">About ${esc(name)}</h2><span class="rule"></span>${copy}</div><div>${swatch("ph--wide")}</div></div></section>`;
}

function services(variant: string): string {
  const items = ["Consultation", "Design & Build", "Ongoing Support"];
  if (variant === "list") {
    const rows = items.map((s) => `<div class="row" data-reveal><div class="dot"></div><div><h3>${s}</h3><p>Short description of what this includes.</p></div></div>`).join("");
    return `<section class="sec services">${title("Our Services")}<div class="wrap stack">${rows}</div></section>`;
  }
  if (variant === "steps") {
    const steps: [string, string, string][] = [
      ["01", "Consultation", "We start by learning about your goals."],
      ["02", "Design & Build", "We craft the right solution for you."],
      ["03", "Launch & Support", "We go live and stay by your side."],
    ];
    const rows = steps.map(([n, t, d]) => `<div class="step" data-reveal><div class="step-n">${n}</div><div><h3>${t}</h3><p>${d}</p></div></div>`).join("");
    return `<section class="sec services">${title("How it works")}<div class="wrap stack">${rows}</div></section>`;
  }
  if (variant === "reactive") {
    const list = ["Consultation", "Design & Build", "Ongoing Support", "Launch & Care"];
    const rows = list.map((s, i) => `<div class="ways-row"><span class="ways-tag">0${i + 1}</span><div><h3>${s}</h3><p>Hover to see what this includes.</p></div></div>`).join("");
    const figs = list.map((_, i) => `<div class="ways-fig f${i}"></div>`).join("");
    return `<section class="sec services">${title("Our Services")}<div class="wrap ways" data-reveal><div class="ways-list">${rows}</div><div class="ways-media">${figs}</div></div></section>`;
  }
  const cardCls = variant === "cards" ? "card card--icon" : "card";
  const cards = items.map((s) => `<div class="${cardCls}" data-reveal><div class="ico"></div><h3>${s}</h3><p>Short description of what this includes.</p></div>`).join("");
  return `<section class="sec services">${title("Our Services")}<div class="wrap grid-3">${cards}</div></section>`;
}

function products(variant: string): string {
  const items: [string, string][] = [["Best Seller", "₱480"], ["New Arrival", "₱350"], ["Signature", "₱620"], ["Gift Set", "₱1,200"]];
  const p = ([n, price]: [string, string], i: number) => `<div class="card prod" data-reveal>${swatch("ph--sq tint" + (i % 3))}<h3>${n}</h3><span class="price">${price}</span></div>`;
  if (variant === "featured") {
    const feat = `<div class="prod-feat" data-reveal>${swatch("ph--tall")}<div><h3>This month's favourite</h3><p>Hand-picked by our team — a customer favourite.</p><span class="price">₱999</span>${btn("Shop now")}</div></div>`;
    return `<section class="sec products">${title("Products")}<div class="wrap">${feat}<div class="grid-3">${items.slice(0, 3).map(p).join("")}</div></div></section>`;
  }
  if (variant === "list") {
    const rows = items.map(([n, price], i) => `<div class="row prow" data-reveal>${swatch("ph--sm tint" + (i % 3))}<div class="prow-main"><h3>${n}</h3><p>Quality you can count on.</p></div><div class="prow-side"><span class="price">${price}</span>${btn("Add")}</div></div>`).join("");
    return `<section class="sec products">${title("Products")}<div class="wrap stack">${rows}</div></section>`;
  }
  if (variant === "retail") {
    const was: Record<string, string> = { "₱480": "₱600", "₱350": "₱450", "₱620": "₱780", "₱1,200": "₱1,500" };
    const rp = ([n, price]: [string, string], i: number) => `<div class="card rcard" data-reveal>${swatch("ph--sq tint" + (i % 3))}<span class="ribbon">SALE</span><button type="button" class="wish" aria-label="Wishlist">&#9829;</button><h3>${n}</h3><div class="rprice"><span class="was">${was[price] ?? ""}</span><span class="price">${price}</span></div><div class="ract">${btn("Buy now")}<button type="button" class="cart" aria-label="Add to cart">+</button></div></div>`;
    return `<section class="sec products">${title("Featured Products")}<div class="wrap grid-4">${items.map(rp).join("")}</div></section>`;
  }
  return `<section class="sec products">${title("Featured Products")}<div class="wrap grid-4">${items.map(p).join("")}</div></section>`;
}

function gallery(variant: string): string {
  if (variant === "spotlight") {
    const small = Array.from({ length: 4 }, () => `<div class="ph tile" data-reveal></div>`).join("");
    return `<section class="sec gallery">${title("Gallery")}<div class="wrap gspot" data-reveal><div class="ph gspot-big"></div><div class="gspot-grid">${small}</div></div></section>`;
  }
  if (variant === "mosaic") {
    const cls = ["m-big", "m-sm", "m-sm", "m-sm", "m-sm"];
    const cells = cls.map((c, i) => `<div class="ph tile ${c} t${i % 4}" data-reveal></div>`).join("");
    return `<section class="sec gallery">${title("Gallery")}<div class="wrap mosaic-grid">${cells}</div></section>`;
  }
  const n = variant === "masonry" ? 7 : 6;
  const cls = variant === "masonry" ? "masonry" : "grid-3";
  const cells = Array.from({ length: n }, (_, i) => `<div class="ph tile t${i % 4}" data-reveal></div>`).join("");
  return `<section class="sec gallery">${title("Gallery")}<div class="wrap ${cls}">${cells}</div></section>`;
}

function pricing(variant: string): string {
  const tiers: [string, string, string[]][] = [
    ["Basic", "₱999", ["1 site", "Email support"]],
    ["Pro", "₱1,999", ["3 sites", "Priority support", "Analytics"]],
    ["Full", "₱3,499", ["Unlimited", "24/7 support", "Custom domain"]],
  ];
  if (variant === "table") {
    const head = `<tr><th>Plan</th>${tiers.map((t, i) => `<th${i === 1 ? ' class="hi"' : ""}>${t[0]}</th>`).join("")}</tr>`;
    const prices = `<tr class="prices"><td>Price / mo</td>${tiers.map((t) => `<td>${t[1]}</td>`).join("")}</tr>`;
    const rows = ["Websites", "Support", "Analytics"].map((f, i) => `<tr><td>${f}</td>${tiers.map((t) => `<td>${t[2][i] ?? "—"}</td>`).join("")}</tr>`).join("");
    const cta = `<tr class="cta"><td></td>${tiers.map(() => `<td>${btn("Choose", "ghost")}</td>`).join("")}</tr>`;
    return `<section class="sec pricing">${title("Pricing")}<div class="wrap" data-reveal><table class="ptable">${head}${prices}${rows}${cta}</table></div></section>`;
  }
  if (variant === "single") {
    const feats = ["Everything included", "Priority support", "Cancel anytime"];
    return `<section class="sec pricing">${title("Simple pricing")}<div class="wrap narrow" data-reveal><div class="card price-card single"><span class="badge">Best value</span><h3>All-in-one</h3><div class="amt">₱1,999<span class="per">/mo</span></div><ul>${feats.map((f) => `<li>${f}</li>`).join("")}</ul>${btn("Get started")}</div></div></section>`;
  }
  const cards = tiers.map((t, i) => `<div class="card price-card ${i === 1 ? "featured" : ""}" data-reveal>${i === 1 ? '<span class="badge">Most popular</span>' : ""}<h3>${t[0]}</h3><div class="amt">${t[1]}</div><ul>${t[2].map((f) => `<li>${f}</li>`).join("")}</ul>${btn("Choose")}</div>`).join("");
  return `<section class="sec pricing">${title("Pricing")}<div class="wrap grid-3">${cards}</div></section>`;
}

function testimonials(variant: string): string {
  if (variant === "single") {
    return `<section class="sec testi testi--single"><div class="wrap narrow" data-reveal><p class="quote">"Best decision we made for our business — fast, friendly and genuinely professional."</p><span class="who">— Bea R., long-time client</span></div></section>`;
  }
  if (variant === "grid") {
    const items: [string, string][] = [
      ['"Best decision we made for our business."', "— Marco D."],
      ['"Fast, friendly, and always on time."', "— Lira P."],
      ['"Highly recommended to everyone."', "— Bea R."],
    ];
    const cards = items.map(([q, w]) => `<div class="card testi-card" data-reveal><p>${q}</p><span class="who">${w}</span></div>`).join("");
    return `<section class="sec testi">${title("What clients say")}<div class="wrap grid-3">${cards}</div></section>`;
  }
  if (variant === "marquee") {
    const qs: [string, string][] = [
      ['"Best decision we made for our business."', "— Marco D."],
      ['"Fast, friendly, and always on time."', "— Lira P."],
      ['"Highly recommended to everyone."', "— Bea R."],
      ['"Professional from start to finish."', "— Jun T."],
    ];
    const cards = qs.map(([q, w]) => `<div class="card testi-card tm-card"><p>${q}</p><span class="who">${w}</span></div>`).join("");
    return `<section class="sec testi">${title("What clients say")}<div class="marquee"><div class="marquee-track testi-marq">${cards + cards}</div></div></section>`;
  }
  const quotes: [string, string][] = [
    ['"Best decision we made for our business."', "— Marco D."],
    ['"Fast, friendly, and always on time."', "— Lira P."],
  ];
  const cards = quotes.map(([q, who]) => `<div class="card testi-card" data-reveal><p>${q}</p><span class="who">${who}</span></div>`).join("");
  return `<section class="sec testi">${title("What clients say")}<div class="wrap grid-2">${cards}</div></section>`;
}

function faq(variant: string): string {
  const qs: [string, string][] = [
    ["How do I get started?", "Reach out and we'll guide you through every step."],
    ["What areas do you serve?", "We serve clients nationwide, online and on-site."],
    ["How much does it cost?", "Flexible plans to fit any budget — see pricing above."],
  ];
  if (variant === "accordion") {
    const rows = qs.map((q, i) => `<details class="acc" ${i === 0 ? "open" : ""} data-reveal><summary>${q[0]}</summary><p>${q[1]}</p></details>`).join("");
    return `<section class="sec faq">${title("FAQ")}<div class="wrap narrow">${rows}</div></section>`;
  }
  if (variant === "columns") {
    const more: [string, string][] = [...qs, ["Can I get a custom plan?", "Yes — reach out and we'll tailor one for you."]];
    const rows = more.map((q) => `<div class="qa" data-reveal><h3>${q[0]}</h3><p>${q[1]}</p></div>`).join("");
    return `<section class="sec faq">${title("FAQ")}<div class="wrap grid-2">${rows}</div></section>`;
  }
  const rows = qs.map((q) => `<div class="qa" data-reveal><h3>${q[0]}</h3><p>${q[1]}</p></div>`).join("");
  return `<section class="sec faq">${title("FAQ")}<div class="wrap narrow stack">${rows}</div></section>`;
}

function blog(variant: string): string {
  const posts = ["5 tips to get you started", "A look behind the scenes", "A client success story"];
  if (variant === "list") {
    const rows = posts.map((t) => `<div class="row post" data-reveal>${swatch("ph--sm")}<div><h3>${t}</h3><p>A short preview of the article…</p></div></div>`).join("");
    return `<section class="sec blog">${title("From the Blog")}<div class="wrap stack">${rows}</div></section>`;
  }
  if (variant === "featured") {
    const feat = `<div class="blog-feat card" data-reveal>${swatch("ph--wide")}<div><h3>${posts[0]}</h3><p>A longer preview of the featured article that draws the reader in and makes them want to read more…</p>${btn("Read more", "ghost")}</div></div>`;
    const rest = posts.slice(1).map((t) => `<div class="row post" data-reveal>${swatch("ph--sm")}<div><h3>${t}</h3><p>A short preview of the article…</p></div></div>`).join("");
    return `<section class="sec blog">${title("From the Blog")}<div class="wrap">${feat}<div class="stack blog-rest">${rest}</div></div></section>`;
  }
  const cards = posts.map((t) => `<div class="card post-card" data-reveal>${swatch("ph--wide")}<h3>${t}</h3><p>A short preview of the article…</p></div>`).join("");
  return `<section class="sec blog">${title("From the Blog")}<div class="wrap grid-3">${cards}</div></section>`;
}

function contact(variant: string): string {
  const form = `<form class="cform" onsubmit="return false"><input placeholder="Your name"><input placeholder="Email"><textarea placeholder="Message"></textarea>${btn("Send message")}</form>`;
  if (variant === "centered") {
    return `<section class="sec contact contact--centered">${title("Contact us")}<div class="wrap narrow" data-reveal>${form}</div></section>`;
  }
  if (variant === "boxed") {
    const binfo = `<div class="cinfo"><h3>Get in touch</h3><p class="cline">hello@business.ph</p><p class="cline">+63 900 000 0000</p><p class="cline">Manila, Philippines</p></div>`;
    return `<section class="sec contact contact--boxed">${title("Contact us")}<div class="wrap narrow" data-reveal><div class="card cbox"><div class="cbox-grid">${binfo}<div>${form}</div></div></div></div></section>`;
  }
  if (variant === "hours") {
    const days: [string, string][] = [["Mon – Sat", "9:00 AM – 6:00 PM"], ["Sunday", "Closed"]];
    const dl = days.map(([d, h]) => `<div class="hrow"><dt>${d}</dt><dd>${h}</dd></div>`).join("");
    const info = `<div class="cinfo"><h2 class="col-head">Visit us</h2><span class="rule"></span><span class="open-status" data-open="9" data-close="18" data-days="1,2,3,4,5,6">Checking hours…</span><dl class="hours">${dl}</dl><p class="cline">Manila, Philippines</p></div>`;
    return `<section class="sec contact contact--split"><div class="wrap cgrid" data-reveal>${info}<div>${form}</div></div></section>`;
  }
  if (variant === "map") {
    const info = `<div class="cinfo"><h2 class="col-head">Visit us</h2><span class="rule"></span><p class="cline">123 Mabini St, Manila, Philippines</p><p class="cline">hello@business.ph</p><p class="cline">+63 900 000 0000</p></div>`;
    const map = `<div class="cmap" aria-hidden="true"><span class="cmap-pin"></span></div>`;
    return `<section class="sec contact contact--map"><div class="wrap cgrid" data-reveal><div class="cmap-col">${info}${form}</div>${map}</div></section>`;
  }
  const info = `<div class="cinfo"><h2 class="col-head">Contact us</h2><span class="rule"></span><p>We'd love to hear from you — send a message and we'll get back to you shortly.</p><p class="cline">hello@business.ph</p><p class="cline">+63 900 000 0000</p><p class="cline">Manila, Philippines</p></div>`;
  return `<section class="sec contact contact--split"><div class="wrap cgrid" data-reveal>${info}<div>${form}</div></div></section>`;
}

/** Team / staff section — believable people with initial-avatar placeholders. */
function team(variant: string): string {
  const members = [
    { name: "Maria Santos", role: "Founder & CEO" },
    { name: "James Rivera", role: "Operations Lead" },
    { name: "Aisha Cruz", role: "Head of Design" },
    { name: "Leo Tan", role: "Client Success" },
  ];
  const initials = (n: string) => n.split(" ").map((w) => w[0]).join("").slice(0, 2);
  if (variant === "cards") {
    const cards = members
      .map(
        (m) =>
          `<div class="card tcard"><span class="tavatar">${esc(initials(m.name))}</span><h3>${esc(m.name)}</h3><p class="trole">${esc(m.role)}</p><p class="tbio">Years of hands-on experience and a genuine care for getting every detail right.</p></div>`,
      )
      .join("");
    return `<section class="sec team">${title("Meet the team")}<div class="wrap grid-4" data-reveal>${cards}</div></section>`;
  }
  const cells = members
    .map(
      (m) =>
        `<figure class="tmember"><span class="tphoto">${esc(initials(m.name))}</span><figcaption><span class="tname">${esc(m.name)}</span><span class="trole">${esc(m.role)}</span></figcaption></figure>`,
    )
    .join("");
  return `<section class="sec team">${title("Meet the team")}<div class="wrap grid-4" data-reveal>${cells}</div></section>`;
}

function statsSection(variant: string): string {
  const items: [number, string, string][] = [[500, "+", "Happy clients"], [10, "", "Years experience"], [98, "%", "Satisfaction"], [24, "/7", "Support"]];
  const cells = items.map(([n, suf, l]) => `<div class="stat" data-reveal><div class="num" data-count="${n}" data-suffix="${suf}">0${suf}</div><div class="lbl">${l}</div></div>`).join("");
  if (variant === "split") {
    return `<section class="sec stats"><div class="wrap two" data-reveal><div><h2 class="col-head">By the numbers</h2><span class="rule"></span><p>Real results our clients count on, backed by years of trusted work.</p></div><div class="stat-grid">${cells}</div></div></section>`;
  }
  return `<section class="sec stats">${title("By the numbers")}<div class="wrap stat-row">${cells}</div></section>`;
}

function logosSection(variant: string): string {
  const logos = Array.from({ length: 6 }, () => `<div class="logo-ph"></div>`).join("");
  if (variant === "marquee") {
    return `<section class="sec logos"><div class="wrap"><p class="logos-cap">Trusted by leading brands</p></div><div class="marquee"><div class="marquee-track">${logos + logos}</div></div></section>`;
  }
  return `<section class="sec logos"><div class="wrap"><p class="logos-cap">Trusted by leading brands</p><div class="logo-strip">${logos}</div></div></section>`;
}

function ctaSection(variant: string, name: string): string {
  const head = `Ready to get started with ${esc(name)}?`;
  const sub = "Let's build something great together — reach out today.";
  if (variant === "split") {
    return `<section class="sec cta-band cta-band--split" data-reveal><div class="wrap two"><div><h2>${head}</h2><p>${sub}</p></div><div class="cta-act">${btn("Get in touch")}</div></div></section>`;
  }
  return `<section class="sec cta-band cta-band--centered" data-reveal><div class="wrap"><h2>${head}</h2><p>${sub}</p>${btn("Get in touch")}</div></section>`;
}

function pageSection(section: string, variant: string, name: string): string {
  currentEyebrow = EYEBROWS[section] ?? "";
  switch (section) {
    case "stats": return statsSection(variant);
    case "logos": return logosSection(variant);
    case "cta": return ctaSection(variant, name);
    case "about": return about(variant, name);
    case "services": return services(variant);
    case "products": return products(variant);
    case "gallery": return gallery(variant);
    case "pricing": return pricing(variant);
    case "testimonials": return testimonials(variant);
    case "faq": return faq(variant);
    case "blog": return blog(variant);
    case "team": return team(variant);
    case "contact": return contact(variant);
    default: return "";
  }
}

// ---- Footer -------------------------------------------------------------
function footer(variant: string, name: string, nav: NavItem[], logoSrc?: string | null): string {
  const links = nav.map(navLink).join("");
  const logo = logoMarkup(name, logoSrc);
  if (variant === "columns") {
    return `<footer class="site-footer ftr--columns"><div class="wrap grid-4"><div>${logo}<p>© ${esc(name)}. All rights reserved.</p></div><div><h4>Company</h4><a href="#">About</a><a href="#">Careers</a><a href="#">Contact</a></div><div><h4>Explore</h4>${links}</div><div><h4>Support</h4><a href="#">Help Center</a><a href="#">FAQ</a><a href="#">Privacy</a></div></div></footer>`;
  }
  if (variant === "cta") {
    return `<footer class="site-footer ftr--cta"><div class="wrap"><h3>Ready to get started with ${esc(name)}?</h3><p class="sub">Let's build something great together — reach out today.</p><a href="#" class="cta-link">Contact us →</a><nav>${links}</nav><p class="copy">© ${esc(name)}. All rights reserved.</p></div></footer>`;
  }
  if (variant === "wordmark") {
    return `<footer class="site-footer ftr--wordmark"><div class="wrap">${logo}<nav>${links}</nav></div><div class="wordmark">${esc(name)}</div><div class="wrap"><p class="copy">© ${esc(name)}. All rights reserved.</p></div></footer>`;
  }
  return `<footer class="site-footer ftr--simple"><div class="wrap">${logo}<nav>${links}</nav><p>© ${esc(name)}. All rights reserved.</p></div></footer>`;
}

// ---- Styles + script ----------------------------------------------------
function styles(theme: KioskTheme): string {
  const p = theme.palette;
  const scale = SCALE_VARS[theme.scale] ?? SCALE_VARS.standard;
  // Surfaces adapt to light vs dark themes so cards, footers, borders and muted
  // text stay legible on a dark background instead of assuming white.
  const surfaces = theme.dark
    ? "--card:color-mix(in srgb,var(--bg) 82%,#fff);--footer-bg:color-mix(in srgb,var(--bg) 82%,#000);--line:color-mix(in srgb,#fff 22%,transparent);--muted:color-mix(in srgb,var(--text) 68%,transparent)"
    : "--card:#fff;--footer-bg:var(--text);--line:color-mix(in srgb,var(--text) 12%,transparent);--muted:color-mix(in srgb,var(--text) 74%,transparent)";
  // Hand-drawn underline (a wavy stroke) used for section rules in handwritten mode.
  const hwMask = `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 16'%3E%3Cpath d='M2 11 Q18 3 34 9 T66 8 T98 6' fill='none' stroke='black' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat center/contain`;
  return `
:root{--primary:${p.primary};--accent:${p.accent};--bg:${p.bg};--text:${p.text};--head:'${theme.fonts.heading}',Georgia,serif;--body:'${theme.fonts.body}',system-ui,sans-serif;${surfaces};${scale}}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:var(--body);line-height:1.6;font-size:var(--fs)}
h1,h2,h3,h4,.logo{font-family:var(--head);line-height:1.15}
img{max-width:100%}a{color:inherit;text-decoration:none}
.wrap{max-width:1080px;margin:0 auto;padding:0 24px}
.wrap.narrow{max-width:720px}
.sec{padding:var(--sec-pad) 0}
.sec-head{max-width:1080px;margin:0 auto 28px;padding:0 24px}
.eyebrow{display:inline-block;font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace;font-size:12px;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:var(--primary);margin-bottom:8px}
.sec-head h2{font-size:var(--h2);font-weight:800}
.rule{display:block;width:44px;height:4px;border-radius:4px;background:var(--accent);margin-top:10px}
.rule.center{margin:14px auto 0}
.logo{font-size:20px;font-weight:800;color:var(--primary)}
.logo-img{height:38px;width:auto;max-width:190px;object-fit:contain;display:block}
.site-footer .logo-img{height:34px;max-width:170px}
.btn{display:inline-block;padding:11px 22px;border-radius:999px;font-weight:700;font-size:14px;transition:transform .15s ease,opacity .15s ease}
.btn:hover{transform:translateY(-2px)}
.btn--solid{background:var(--primary);color:#fff}
.btn--ghost{border:2px solid var(--primary);color:var(--primary)}
.ph{background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:14px;min-height:120px;opacity:.9}
.ph--tall{min-height:320px}.ph--wide{min-height:220px}.ph--sq{aspect-ratio:1}.ph--sm{width:120px;min-height:80px;flex:0 0 auto}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center}
.two.reverse>*:first-child{order:2}
.stack{display:flex;flex-direction:column;gap:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.card h3{font-size:18px;margin-bottom:6px}.card p{color:var(--muted)}
.ico,.dot{width:40px;height:40px;border-radius:12px;background:var(--accent);margin-bottom:12px}
.dot{width:14px;height:14px;border-radius:50%;margin:6px 0 0;flex:0 0 auto}
.row{display:flex;gap:16px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.price{color:var(--primary);font-weight:800;display:block;margin-top:6px}
.prod-feat{display:grid;grid-template-columns:1fr 1.4fr;gap:24px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:24px;margin-bottom:20px}
.price-card{text-align:center}
.price-card .amt{font-family:var(--head);font-size:32px;font-weight:800;color:var(--primary);margin:6px 0}
.price-card ul{list-style:none;margin:12px 0}
.price-card li{padding:5px 0;color:var(--muted);border-top:1px solid var(--line)}
.price-card.featured{border-color:var(--primary);transform:scale(1.03)}
.ptable{width:100%;border-collapse:collapse;background:var(--card);border-radius:14px;overflow:hidden}
.ptable th,.ptable td{padding:12px 14px;text-align:center;border-bottom:1px solid var(--line)}
.ptable th:first-child,.ptable td:first-child{text-align:left}
.ptable .prices td{font-weight:800;color:var(--primary)}
.masonry{columns:3;column-gap:14px}.masonry .tile{width:100%;margin-bottom:14px;break-inside:avoid}
.tile{min-height:150px}.t1{min-height:210px}.t2{min-height:120px}.t3{min-height:180px}
.quote{font-family:var(--head);font-size:24px;line-height:1.4}
.who{color:var(--primary);font-weight:700;display:block;margin-top:10px}
.acc{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin-bottom:10px}
.acc summary{font-weight:700;cursor:pointer}.acc p{margin-top:8px;color:var(--muted)}
.qa h3{margin-bottom:4px}
.cform{display:flex;flex-direction:column;gap:12px}
.cform input,.cform textarea{padding:12px 14px;border:1px solid var(--line);border-radius:10px;font:inherit;background:var(--bg)}
.cform textarea{min-height:120px}
.post-card .ph{margin-bottom:12px}
.hero--left{padding:var(--hero-pad) 0;text-align:left}.hero--left h1,.hero--left p{max-width:640px}.hero--left .btns{justify-content:flex-start}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-top:32px}
.stat{text-align:center}.stat .num{font-family:var(--head);font-size:34px;font-weight:800;color:var(--primary)}.stat .lbl{color:var(--muted);font-size:14px}
.step{display:flex;gap:18px;align-items:flex-start;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}
.step-n{font-family:var(--head);font-size:32px;font-weight:800;color:var(--accent);flex:0 0 auto;line-height:1}
.prow{align-items:center}.prow-main{flex:1}.prow-main p{color:var(--muted);font-size:13px}.prow-side{flex:0 0 auto;display:flex;flex-direction:column;gap:6px;align-items:flex-end}
.gspot{display:grid;grid-template-columns:1.5fr 1fr;gap:14px}.gspot-big{min-height:330px}.gspot-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.gspot-grid .tile{min-height:158px}
.price-card.single{max-width:360px;margin:0 auto}.price-card .per{font-size:14px;color:var(--muted);font-family:var(--body)}
.blog-feat{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:center}.blog-rest{margin-top:16px}
.cbox{padding:28px}.cbox-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:32px;align-items:start}.contact--boxed .cform{max-width:none;margin:0}
.hl{color:var(--primary)}
.card{transition:transform .2s ease,box-shadow .22s ease}
.card:hover{transform:translateY(-4px);box-shadow:0 14px 30px -14px rgba(0,0,0,.2)}
.dark .card:hover{box-shadow:0 14px 30px -14px rgba(0,0,0,.5),0 0 0 1px color-mix(in srgb,#fff 12%,transparent)}
.tile{transition:transform .3s ease,filter .3s ease}
.gallery .tile:hover{transform:scale(1.04)}
/* Corner style (radius knob) */
.rad-sharp .card,.rad-sharp .ph,.rad-sharp .btn,.rad-sharp .tile,.rad-sharp .row,.rad-sharp .cform input,.rad-sharp .cform textarea,.rad-sharp .acc,.rad-sharp .ptable,.rad-sharp .prod-feat,.rad-sharp .cbox,.rad-sharp .ico,.rad-sharp .hero-bleed-art,.rad-sharp .ways-fig,.rad-sharp .cart,.rad-sharp .logo-ph,.rad-sharp .canvas-motif{border-radius:0}
.rad-sharp .btn{border-radius:2px}
.rad-pill .card,.rad-pill .ph,.rad-pill .prod-feat,.rad-pill .cbox,.rad-pill .hero-bleed-art,.rad-pill .ways-fig{border-radius:28px}.rad-pill .tile{border-radius:22px}.rad-pill .cform input,.rad-pill .cform textarea{border-radius:16px}
/* Stats / Logos / CTA bands */
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.logos-cap{text-align:center;color:var(--muted);font-size:13px;text-transform:uppercase;letter-spacing:.14em;margin-bottom:20px}
.logo-strip{display:flex;justify-content:center;align-items:center;gap:44px;flex-wrap:wrap}
.logo-ph{width:110px;height:34px;border-radius:8px;background:var(--muted);opacity:.5}
.marquee{overflow:hidden;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}
.marquee-track{display:flex;gap:44px;width:max-content;animation:marq 20s linear infinite}
.marquee:hover .marquee-track{animation-play-state:paused}
@keyframes marq{to{transform:translateX(-50%)}}
.cta-band{background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 88%,#000),color-mix(in srgb,var(--accent) 88%,#000));color:#fff;position:relative;overflow:hidden}
.cta-band::before{content:"";position:absolute;width:320px;height:320px;border-radius:50%;background:#fff;opacity:.12;filter:blur(70px);top:-120px;right:-60px}
.cta-band .wrap{position:relative}
.cta-band h2{color:#fff;font-size:30px}
.cta-band p{color:rgba(255,255,255,.85);margin:10px 0 20px;max-width:52ch}
.cta-band .btn--solid{background:#fff;color:#111}
.cta-band--centered{text-align:center}.cta-band--centered p{margin-left:auto;margin-right:auto}
.cta-band--split .wrap{align-items:center}.cta-band--split .cta-act{text-align:right}
/* Tier 2 variants */
.hero--bleed{position:relative;overflow:hidden;padding:var(--hero-pad) 0}
.hero--bleed .wrap{position:relative;z-index:2}
.hero--bleed .hero-copy{max-width:54%}
.hero-bleed-art{position:absolute;top:50%;right:-6%;transform:translateY(-50%);width:52%;height:78%;background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:24px;-webkit-mask:linear-gradient(90deg,transparent,#000 24%);mask:linear-gradient(90deg,transparent,#000 24%);opacity:.92;box-shadow:0 30px 60px -30px rgba(0,0,0,.35)}
.rcard{position:relative;overflow:hidden}
.rcard .ribbon{position:absolute;top:14px;left:-30px;transform:rotate(-45deg);background:var(--accent);color:#fff;font-size:11px;font-weight:700;letter-spacing:.08em;padding:3px 36px}
.rcard .wish{position:absolute;top:10px;right:10px;width:30px;height:30px;border-radius:50%;background:var(--card);border:1px solid var(--line);color:var(--primary);cursor:pointer;line-height:1}
.rprice{display:flex;gap:8px;align-items:baseline;margin-top:6px}
.was{text-decoration:line-through;color:var(--muted);font-size:13px}
.ract{display:flex;gap:8px;margin-top:12px;align-items:center}
.cart{width:38px;height:38px;border-radius:10px;background:var(--text);color:var(--bg);border:0;font-size:20px;cursor:pointer;flex:0 0 auto}
.mosaic-grid{display:grid;grid-template-columns:repeat(4,1fr);grid-auto-rows:157px;gap:14px}
.m-big{grid-column:span 2;grid-row:span 2}
.testi-marq .tm-card{width:340px;flex:0 0 auto}
.ftr--wordmark .wrap{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.ftr--wordmark nav{display:flex;gap:18px}.ftr--wordmark nav a{display:inline}
.wordmark{font-family:var(--head);font-weight:800;font-size:clamp(56px,17vw,190px);line-height:.9;text-align:center;color:rgba(255,255,255,.08);padding:14px 0 4px;white-space:nowrap;overflow:hidden}
.ftr--wordmark .copy{text-align:center;color:rgba(255,255,255,.55);font-size:13px}
.ways{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:stretch}
.ways-list{display:flex;flex-direction:column}
.ways-row{display:flex;gap:16px;align-items:flex-start;padding:16px 0;border-top:1px solid var(--line);cursor:pointer;transition:opacity .2s ease}
.ways-row:last-child{border-bottom:1px solid var(--line)}
.ways-tag{font-family:var(--head);font-weight:800;color:var(--accent);flex:0 0 auto}
.ways-media{position:relative;min-height:340px}
.ways-fig{position:absolute;inset:0;border-radius:16px;background:linear-gradient(135deg,var(--primary),var(--accent));opacity:0;transition:opacity .35s ease}
.ways-fig.f0{opacity:1}.ways-fig.f1{filter:hue-rotate(30deg)}.ways-fig.f2{filter:hue-rotate(-25deg) saturate(1.3)}.ways-fig.f3{filter:hue-rotate(60deg)}
.ways:has(.ways-row:nth-of-type(1):hover) .ways-fig{opacity:0}.ways:has(.ways-row:nth-of-type(1):hover) .f0{opacity:1}
.ways:has(.ways-row:nth-of-type(2):hover) .ways-fig{opacity:0}.ways:has(.ways-row:nth-of-type(2):hover) .f1{opacity:1}
.ways:has(.ways-row:nth-of-type(3):hover) .ways-fig{opacity:0}.ways:has(.ways-row:nth-of-type(3):hover) .f2{opacity:1}
.ways:has(.ways-row:nth-of-type(4):hover) .ways-fig{opacity:0}.ways:has(.ways-row:nth-of-type(4):hover) .f3{opacity:1}
.hours{margin:14px 0}.hours .hrow{display:flex;justify-content:space-between;gap:16px;padding:7px 0;border-top:1px solid var(--line)}.hours dt{font-weight:600}.hours dd{color:var(--muted)}
.open-status{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700;background:color-mix(in srgb,var(--text) 12%,var(--bg));color:var(--text)}
.open-status.is-open{background:#16a34a;color:#fff}.open-status.is-closed{background:#8a8f98;color:#fff}
/* Tier 3 flourishes */
.hero--canvas{position:relative;background:var(--footer-bg);color:#fff;min-height:500px;display:flex;align-items:flex-end;overflow:hidden;padding:0}
.hero--canvas .wrap{position:relative;z-index:2;padding-bottom:48px}
.canvas-eyebrow{display:block;font-family:ui-monospace,Menlo,monospace;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:14px;max-width:40ch}
.canvas-mark{font-family:var(--head);font-weight:800;font-size:clamp(48px,11vw,130px);line-height:.92;color:#fff}
.canvas-lines{position:absolute;inset:44px;border-left:1px solid rgba(255,255,255,.2);pointer-events:none}
.canvas-lines::after{content:"";position:absolute;left:0;top:42%;width:100%;height:1px;background:rgba(255,255,255,.2)}
.canvas-motif{position:absolute;top:44px;right:44px;width:120px;height:120px;background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:20px;transform:rotate(-12deg);animation:float 9s ease-in-out infinite}
.hero--collage{position:relative;overflow:hidden;text-align:center;padding:var(--hero-pad) 0}
.hero--collage .wrap{position:relative;z-index:2}.hero--collage p{margin-left:auto;margin-right:auto}
.cl-blob{position:absolute;border-radius:50%;filter:blur(46px);opacity:.5;transition:transform .2s ease-out;pointer-events:none}
.b-a{width:300px;height:300px;background:var(--primary);top:-50px;left:6%}
.b-b{width:250px;height:250px;background:var(--accent);bottom:-40px;right:8%}
.cl-ring{position:absolute;width:180px;height:180px;border:14px solid var(--accent);border-radius:50%;opacity:.25;top:16%;right:20%;transition:transform .2s ease-out;pointer-events:none}
.btn{position:relative;overflow:hidden;z-index:1}
.btn-t{position:relative;z-index:2;display:inline-block}
/* CTA hover effects — one global choice via the cta-<effect> body class. */
/* wipe: a colour fill sweeps across (the long-standing default) */
.cta-wipe .btn::before{content:"";position:absolute;inset:0;background:rgba(0,0,0,.14);transform:scaleX(0);transform-origin:left;transition:transform .3s ease;z-index:0}
.cta-wipe .btn:hover::before{transform:scaleX(1)}
/* gradient swipe: an animated gradient slides through the solid button */
.cta-gradient .btn--solid{background:linear-gradient(120deg,var(--primary),var(--accent),var(--primary));background-size:220% 100%;background-position:0 0;transition:background-position .5s ease,transform .15s ease}
.cta-gradient .btn--solid:hover{background-position:100% 0}
.cta-gradient .btn--ghost:hover{background:color-mix(in srgb,var(--primary) 12%,transparent)}
/* glow: an accent glow blooms around the button */
.cta-glow .btn{transition:transform .15s ease,box-shadow .28s ease}
.cta-glow .btn:hover{box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 28%,transparent),0 8px 26px color-mix(in srgb,var(--accent) 45%,transparent)}
/* shine: a light sheen sweeps across the surface */
.cta-shine .btn::after{content:"";position:absolute;top:0;left:-120%;width:55%;height:100%;background:linear-gradient(120deg,transparent,rgba(255,255,255,.55),transparent);transform:skewX(-20deg);transition:left .6s ease;z-index:1;pointer-events:none}
.cta-shine .btn:hover::after{left:135%}
/* flip: the label flips up to a fresh copy */
.cta-flip .btn .btn-t{transition:transform .28s ease}
.cta-flip .btn:hover .btn-t{transform:translateY(-140%)}
.cta-flip .btn::after{content:attr(data-label);position:absolute;inset:0;display:flex;align-items:center;justify-content:center;transform:translateY(140%);transition:transform .28s ease;z-index:2}
.cta-flip .btn:hover::after{transform:translateY(0)}
.site-header{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line);transition:box-shadow .25s ease,background .25s ease}
.site-header.scrolled{background:color-mix(in srgb,var(--bg) 97%,transparent);box-shadow:0 6px 20px rgba(0,0,0,.07)}
.site-header .wrap{display:flex;align-items:center;justify-content:space-between;padding-top:16px;padding-bottom:16px}
.site-header nav{display:flex;gap:22px;font-weight:600;font-size:14px}
.site-header nav a:hover{color:var(--primary)}
.site-header nav a.nav-active,.drawer a.nav-active{color:var(--primary);font-weight:700}
.page-view{display:none}
.page-view.active{display:block}
.hdr--centered .wrap{flex-direction:column;gap:8px}
.burger{background:none;border:0;display:flex;flex-direction:column;gap:5px;cursor:pointer;padding:4px}
.burger span{width:22px;height:2px;background:var(--accent);display:block;transition:transform .25s ease,opacity .25s ease}
.site-header.open .burger span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.site-header.open .burger span:nth-child(2){opacity:0}
.site-header.open .burger span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
.site-header .drawer{display:flex;flex-direction:column;max-height:0;overflow:hidden;transition:max-height .3s ease;padding:0 24px}
.site-header.open .drawer{max-height:360px;padding:6px 24px 16px;border-top:1px solid var(--line)}
.drawer a{padding:9px 0;font-weight:600;border-bottom:1px solid var(--line)}
.drawer a:last-child{border-bottom:0}
.hero{position:relative;overflow:hidden}
.hero h1{font-size:var(--h1);font-weight:800}
.hero p{font-size:18px;color:var(--muted);margin:14px 0 22px;max-width:52ch}
.hero .btns{display:flex;gap:12px}
.hero--image,.hero--banner{color:#fff;text-align:center;padding:var(--hero-pad) 0;background:linear-gradient(135deg,var(--primary),var(--accent))}
.hero--image p,.hero--banner p{color:rgba(255,255,255,.9);margin-left:auto;margin-right:auto}
.hero--image .btn--solid,.hero--banner .btn--solid{background:#fff;color:var(--primary)}
.hero--banner .overlay{position:absolute;inset:0;background:rgba(0,0,0,.25)}
.hero--banner .wrap{position:relative}
.hero--split .wrap{display:grid;grid-template-columns:1.1fr 1fr;gap:40px;align-items:center;padding:calc(var(--sec-pad) + 12px) 24px}
.hero--minimal{text-align:center;padding:var(--hero-pad) 0}
.hero--minimal h1{font-size:var(--hero-min)}
.hero--minimal p{margin-left:auto;margin-right:auto}
.site-footer{background:var(--footer-bg);color:#fff;padding:44px 0;margin-top:20px}
.site-footer .logo{color:#fff}
.site-footer a{color:rgba(255,255,255,.75);display:block;padding:3px 0;font-size:14px}
.site-footer h4{margin-bottom:8px;font-size:14px}
.ftr--simple .wrap{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.ftr--simple nav{display:flex;gap:18px}.ftr--simple nav a{display:inline}
.ftr--cta{text-align:center}.ftr--cta h3{font-size:26px}.ftr--cta .sub{color:rgba(255,255,255,.72);margin:10px 0 18px;max-width:44ch;margin-left:auto;margin-right:auto}
.ftr--cta .cta-link{display:inline-block;font-weight:700;border-bottom:2px solid rgba(255,255,255,.55);padding-bottom:2px}.ftr--cta .cta-link:hover{border-color:#fff}
.ftr--cta nav{display:flex;flex-wrap:wrap;gap:20px;justify-content:center;margin-top:22px}.ftr--cta nav a{display:inline}
.ftr--cta .copy{margin-top:18px;color:rgba(255,255,255,.55);font-size:13px}
[data-reveal]{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease}
[data-reveal].in{opacity:1;transform:none}
/* QA fixes */
.card--icon{text-align:center}.card--icon .ico{margin-left:auto;margin-right:auto}
.prod .ph{margin-bottom:12px}
.ph.tint1{filter:hue-rotate(22deg)}.ph.tint2{filter:saturate(1.35) hue-rotate(-16deg)}
.col-head{font-size:var(--h2);font-weight:800}.about--split .rule{margin-bottom:16px}
.price-card{position:relative}.price-card .badge{position:absolute;top:-11px;left:50%;transform:translateX(-50%);background:var(--primary);color:#fff;font-size:11px;font-weight:700;padding:3px 11px;border-radius:999px;white-space:nowrap}
.ptable th.hi{color:var(--primary)}.ptable .cta td{padding:16px 14px}
.cgrid{display:grid;grid-template-columns:3fr 7fr;gap:44px;align-items:start}
.contact--split .cform{max-width:none;margin:0}
.contact--centered .cform{max-width:480px;margin-left:auto;margin-right:auto}
.cinfo .cline{margin-top:4px;color:var(--muted)}
.testi--single{background:color-mix(in srgb,var(--primary) 8%,var(--bg))}
.hero--image h1,.hero--banner h1{text-shadow:0 2px 18px rgba(0,0,0,.28)}
.hero--image p,.hero--banner p{text-shadow:0 1px 10px rgba(0,0,0,.22)}
.burger-m{display:none}
/* Playful / creative style */
.playful .btn{box-shadow:0 10px 24px -10px var(--primary)}
.playful .hero--image,.playful .hero--banner{background:linear-gradient(120deg,var(--primary),var(--accent),var(--primary));background-size:200% 200%;animation:grad 9s ease infinite}
.bg-blobs{position:fixed;inset:0;overflow:hidden;z-index:-1;pointer-events:none}
.blob{position:absolute;border-radius:50%;filter:blur(64px);opacity:.45}
.b1{width:360px;height:360px;background:var(--primary);top:-90px;left:-70px;animation:float 15s ease-in-out infinite}
.b2{width:320px;height:320px;background:var(--accent);bottom:6%;right:-80px;animation:float 19s ease-in-out infinite reverse}
.b3{width:280px;height:280px;background:var(--primary);top:44%;left:56%;animation:float 23s ease-in-out infinite}
@keyframes float{0%,100%{transform:translate(0,0)}50%{transform:translate(34px,-46px)}}
@keyframes grad{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
/* Handwritten headings (human touch) — body stays in the readable font */
.handwritten h1,.handwritten .sec-head h2,.handwritten .col-head{font-family:'${HANDWRITTEN_FONT}',var(--head);font-weight:700;letter-spacing:.4px}
.handwritten h1{font-size:calc(var(--h1) * 1.12)}
.handwritten .sec-head h2,.handwritten .col-head{font-size:calc(var(--h2) * 1.18)}
.handwritten .rule{height:14px;width:96px;border-radius:0;background:var(--accent);-webkit-mask:${hwMask};mask:${hwMask}}
/* Hero: video background (animated gradient stands in for a looping video) */
.hero--video{position:relative;overflow:hidden;color:#fff;text-align:center;padding:var(--hero-pad) 0}
.hero--video .video-bg{position:absolute;inset:0;background:linear-gradient(120deg,var(--primary),var(--accent),var(--primary));background-size:220% 220%;animation:grad 12s ease infinite}
.hero--video .overlay{position:absolute;inset:0;background:rgba(0,0,0,.4)}
.hero--video .wrap{position:relative;z-index:2}
.hero--video p{color:rgba(255,255,255,.9);margin-left:auto;margin-right:auto}
.hero--video .btn--solid{background:#fff;color:var(--primary)}
.hero--video h1{text-shadow:0 2px 18px rgba(0,0,0,.3)}
.vplay{display:flex;width:66px;height:66px;margin:0 auto 20px;align-items:center;justify-content:center;padding-left:4px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.7);font-size:22px}
/* Team / staff */
.tcard{text-align:center;padding:24px}
.tavatar{display:flex;width:72px;height:72px;margin:0 auto 14px;align-items:center;justify-content:center;border-radius:50%;background:color-mix(in srgb,var(--primary) 14%,var(--card));color:var(--primary);font-family:var(--head);font-weight:800;font-size:24px}
.tcard h3{font-size:17px}
.trole{color:var(--primary);font-size:13px;font-weight:700;margin-top:2px}
.tbio{color:var(--muted);font-size:13px;margin-top:10px}
.tmember{text-align:center}
.tphoto{display:flex;aspect-ratio:1;width:100%;align-items:center;justify-content:center;margin-bottom:12px;border-radius:16px;background:color-mix(in srgb,var(--primary) 12%,var(--card));color:var(--primary);font-family:var(--head);font-weight:800;font-size:34px}
.tmember figcaption{display:flex;flex-direction:column}
.tname{font-family:var(--head);font-weight:700}
/* Contact: map placeholder (street grid + pin) */
.contact--map .cmap-col{display:flex;flex-direction:column;gap:22px}
.cmap{position:relative;min-height:340px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(var(--line) 1px,transparent 1px) 0 0/100% 42px,linear-gradient(90deg,var(--line) 1px,transparent 1px) 0 0/42px 100%,color-mix(in srgb,var(--primary) 6%,var(--card))}
.cmap-pin{position:absolute;top:50%;left:50%;width:22px;height:22px;transform:translate(-50%,-100%) rotate(45deg);background:var(--primary);border-radius:50% 50% 50% 0;box-shadow:0 8px 16px -5px rgba(0,0,0,.45)}
.cmap-pin::after{content:"";position:absolute;top:6px;left:6px;width:8px;height:8px;background:var(--card);border-radius:50%}
@media(max-width:760px){.grid-3,.grid-4,.grid-2,.two,.cgrid,.hero--split .wrap,.prod-feat,.gspot,.blog-feat,.cbox-grid{grid-template-columns:1fr}.stat-row{grid-template-columns:1fr 1fr}.masonry{columns:2}.mosaic-grid{grid-template-columns:1fr 1fr}.ways{grid-template-columns:1fr}.ways-media{display:none}.hero-bleed-art{display:none}.hero--bleed .hero-copy{max-width:100%}.hero h1{font-size:calc(var(--h1) * .8)}.site-header .wrap nav{display:none}.burger-m{display:flex}}
@media(max-width:1000px){.products .grid-4,.team .grid-4{grid-template-columns:1fr 1fr}}
@media(prefers-reduced-motion:reduce){[data-reveal]{opacity:1;transform:none;transition:none}.blob,.playful .hero--image,.playful .hero--banner,.hero--video .video-bg,.marquee-track{animation:none}}
`.trim();
}

const SCRIPT = `
(function(){
 var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.12});
 document.querySelectorAll('[data-reveal]').forEach(function(el){var i=0,n;for(n=el.previousElementSibling;n;n=n.previousElementSibling){if(n.hasAttribute('data-reveal'))i++;}if(i>0&&i<12)el.style.transitionDelay=(i*70)+'ms';io.observe(el)});
 document.querySelectorAll('.burger,.burger-m').forEach(function(b){b.addEventListener('click',function(){var h=b.closest('.site-header');if(h)h.classList.toggle('open')})});
 // Multi-page: show one page at a time; nav links switch the active page (keeps the whole site one self-contained file).
 var PAGES=document.querySelectorAll('[data-page]');
 function showPage(slug){
  if(!PAGES.length)return;var hit=null;
  PAGES.forEach(function(p){var on=p.getAttribute('data-page')===slug;p.classList.toggle('active',on);if(on)hit=p;});
  if(!hit){PAGES[0].classList.add('active');hit=PAGES[0];}
  hit.querySelectorAll('[data-reveal]').forEach(function(el){el.classList.add('in')});
  document.querySelectorAll('[data-nav]').forEach(function(a){a.classList.toggle('nav-active',a.getAttribute('data-nav')===hit.getAttribute('data-page'))});
  try{window.scrollTo(0,0);}catch(e){}
 }
 document.querySelectorAll('[data-nav]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();showPage(a.getAttribute('data-nav'));var h=a.closest('.site-header');if(h)h.classList.remove('open');})});
 (function(){var initial=(location.hash||'').replace('#','');var act=document.querySelector('.page-view.active');var slug=(initial&&document.querySelector('[data-page="'+initial+'"]'))?initial:(act?act.getAttribute('data-page'):(PAGES[0]&&PAGES[0].getAttribute('data-page')));if(slug)showPage(slug);})();
 var hdr=document.querySelector('.site-header');
 if(hdr){window.addEventListener('scroll',function(){hdr.classList.toggle('scrolled',window.scrollY>20)},{passive:true});}
 var px=document.querySelectorAll('[data-parallax]');
 if(px.length&&!matchMedia('(prefers-reduced-motion:reduce)').matches){
  window.addEventListener('scroll',function(){px.forEach(function(el){var r=el.getBoundingClientRect();var inner=el.querySelector('.wrap');if(inner)inner.style.transform='translateY('+(r.top*-0.06)+'px)'})},{passive:true});
 }
 var rm=matchMedia('(prefers-reduced-motion:reduce)').matches;
 document.querySelectorAll('[data-count]').forEach(function(el){
  var t=+el.getAttribute('data-count'),suf=el.getAttribute('data-suffix')||'';
  if(rm){el.textContent=t+suf;return;}
  var co=new IntersectionObserver(function(es){es.forEach(function(e){if(!e.isIntersecting)return;var s=null;function tick(now){if(s===null)s=now;var p=Math.min((now-s)/1200,1);el.textContent=Math.round(t*p)+suf;if(p<1)requestAnimationFrame(tick)}requestAnimationFrame(tick);co.unobserve(el)})},{threshold:.4});
  co.observe(el);
 });
 document.querySelectorAll('.open-status').forEach(function(el){var days=(el.getAttribute('data-days')||'').split(',').map(Number),op=+el.getAttribute('data-open'),cl=+el.getAttribute('data-close');var n=new Date(),h=n.getHours()+n.getMinutes()/60,iso=days.indexOf(n.getDay())>-1&&h>=op&&h<cl;el.textContent=iso?'Open now':'Closed';el.classList.add(iso?'is-open':'is-closed')});
 if(!rm){document.querySelectorAll('[data-parallax-mouse]').forEach(function(h){h.addEventListener('pointermove',function(e){var r=h.getBoundingClientRect(),cx=(e.clientX-r.left)/r.width-0.5,cy=(e.clientY-r.top)/r.height-0.5;h.querySelectorAll('[data-depth]').forEach(function(el){var d=+el.getAttribute('data-depth');el.style.transform='translate('+(cx*d)+'px,'+(cy*d)+'px)'})})})}
})();`.trim();

export function buildSite(input: SiteInput, theme: KioskTheme): string {
  const name = input.businessName.trim() || "Your Business";
  const tagline = (input.websiteType && TAGLINES[input.websiteType]) || "Beautiful websites, built for your business.";
  // At least one page always renders (an empty Home) so the frame is never blank.
  const pages = input.pages.length ? input.pages : [{ name: "Home", slug: "home", hero: null, sections: [] }];
  const nav: NavItem[] = pages.map((p) => ({ label: p.name, slug: p.slug }));
  // The page that opens first: the requested active slug, else the first page.
  const activeIdx = Math.max(0, pages.findIndex((p) => p.slug === input.activeSlug));
  const playful = theme.style === "playful";
  const handwritten = theme.headingStyle === "handwritten";
  const fontFamilies = [theme.fonts.heading, theme.fonts.body];
  if (handwritten) fontFamilies.push(HANDWRITTEN_FONT);
  const bodyClass = `${playful ? "playful" : "clean"}${handwritten ? " handwritten" : ""} rad-${theme.radius || "soft"} cta-${theme.ctaHover || "wipe"}${theme.dark ? " dark" : ""}`;

  // Each page is its own container; only the active one is shown (client-side
  // switching in SCRIPT), so the whole multi-page site stays ONE self-contained file.
  const pageHtml = pages
    .map((p, i) => {
      const inner =
        (p.hero ? hero(p.hero, name, tagline) : "") +
        p.sections.map((s) => pageSection(s.section, s.variant, name)).join("\n");
      return `<div class="page-view${i === activeIdx ? " active" : ""}" id="page-${esc(p.slug)}" data-page="${esc(p.slug)}">${inner}</div>`;
    })
    .join("\n");

  const body = [
    playful ? `<div class="bg-blobs"><span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span></div>` : "",
    input.header ? header(input.header, name, nav, input.logo) : "",
    pageHtml,
    input.footer ? footer(input.footer, name, nav, input.logo) : "",
  ].join("\n");

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(name)}</title>
${fontsLink(fontFamilies)}
<style>${styles(theme)}</style>
</head>
<body class="${bodyClass}">
${body}
<script>${SCRIPT}</script>
</body>
</html>`;
}
