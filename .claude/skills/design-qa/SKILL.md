---
name: design-qa
description: Review UI / frontend (web pages, components, generated markup) for design quality — layout balance, spacing, typographic hierarchy, and a human, professional look that does NOT read as AI-generated. Use when building or auditing any page, section, or component in this platform, especially the Kiosk site-builder templates.
---

# Design QA — make it look human, not AI-generated

Clients increasingly reject layouts that "look AI-made." The goal of this review
is a **human, professional, considered** result. Read the target markup/CSS (or
component), then check it against the sections below and report concrete, ranked
fixes with the exact selector/line to change.

## 1. Layout balance (the most common miss)
- **No orphaned grid cells.** N items in an M-column grid where N≠M (or a multiple)
  leaves a dead gap. Match the column count to the item count, resize items to
  fill, or re-balance the row. (e.g. 3 cards in a 4-col grid → use 3 cols.)
- **Even rhythm.** Equal gaps between siblings; sections share a consistent
  vertical padding; no section visibly tighter/looser than its neighbours.
- **Intentional asymmetry only.** Split/offset layouts must look deliberate
  (clear focal ratio like 1.1fr:1fr), never "slightly off."
- **Fill the width.** A featured block + a short row beneath it should not leave a
  trailing empty column — extend, resize, or add one.

## 2. Spacing & alignment
- **Every element respects the container gutter** — nothing touches the screen
  edge. Section headings must share the SAME left edge as the content below them
  (a heading outside the content wrapper is the classic bug).
- Consistent horizontal padding across header, hero, sections, footer.
- Vertical spacing follows a scale (e.g. 8/16/24/48), not arbitrary values.
- Optical alignment: icons/text baselines line up; cards in a row are equal height.

## 3. Typographic hierarchy
- One clear H1 per page; section titles a consistent size; body has comfortable
  line-height (~1.5–1.65) and measure (~50–75 chars).
- Real contrast between heading and body weight/size — not everything mid-weight.
- Numerals/prices/labels styled deliberately, not left as default body text.

## 4. Colour & depth
- Accent used with restraint (one job), not sprayed everywhere.
- Sufficient text contrast on every surface (incl. coloured/gradient areas and
  footers — light text on a light footer bg is a real failure).
- Depth from subtle shadows/borders, not heavy drop shadows on everything.

## 5. The "looks AI-generated" smells — flag any of these
- Every card identical, evenly gridded, no focal point or variation.
- A lonely single element in a large empty band (e.g. one button in a big footer).
- Generic filler ("Lorem", "Service One/Two/Three") left where a real label belongs.
- Uniform rounded corners + centered everything + one accent pop — the default look.
- Placeholder blocks that never resolve into a believable composition.
- No human touch: nothing hand-made, textured, or asymmetric to break the template feel.

## 6. Human touch (earn the "wow")
- Consider a hand-drawn / handwritten accent for headings or section labels, a
  textured divider, an off-grid element, or a signature detail — something a
  template generator wouldn't produce.
- Micro-interactions and motion should feel crafted (easing, stagger), not canned.
- Copy in the client's voice beats generic marketing filler.

## How to report
For each issue: **severity (high/med/low) · the section/selector · what's wrong ·
the concrete fix.** Rank high-severity (balance, edge-padding, contrast, lonely
elements) first. Prefer fixes that are one CSS/markup change. End with the 1–3
changes that most raise the "human, not AI" feel.
