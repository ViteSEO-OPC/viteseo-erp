---
type: personal-reference
title: Frontend Styling & Architecture — Tailwind, Components, Custom CSS & JS Libraries
tags: [hris, frontend, styling, tailwind, react, components, figma, reference, personal]
owner: devops@viteseo.ph
audience: just me (learning reference)
pairs_with: [HRIS-AI-Build-Guide.md, HRIS-Tech-Stack-How-It-Works.md]
---

# Frontend Styling & Architecture

> **Personal learning reference.** The leap from **vanilla HTML/CSS/JS + Bootstrap** to **React/Next.js + Tailwind**. Covers the three things that trip everyone up:
> 1. Custom styling (when Tailwind isn't enough)
> 2. Page composition (components inside components)
> 3. Bridging JS libraries (three.js, charts) into React
>
> Pairs with `HRIS-Tech-Stack-How-It-Works.md` (the stack) and the build guide (the rules).

---

# 1. Custom styling — yes, there are scenarios, and here's how

Tailwind covers ~95% of styling. For the other 5% there are **four escape hatches**, smallest to biggest.

## (a) Arbitrary values — one-off numbers/colors not on Tailwind's scale
When Figma says exactly `327px` or `#4F46E5` and no utility matches, use square brackets:
```jsx
<div className="w-[327px] bg-[#4F46E5] top-[13px]" />
```
You can even set *arbitrary properties* Tailwind has no class for:
```jsx
<div className="[clip-path:polygon(0_0,100%_0,100%_80%,0_100%)]" />
<div className="[mask-image:linear-gradient(black,transparent)]" />
```

## (b) Inline `style={{}}` — values computed at runtime (dynamic)
When the value isn't known until the app runs (e.g. a progress bar width from data), a fixed class can't express it. Use React's inline style:
```jsx
<div style={{ height: `${percent}%` }} className="bg-green-500" />
```
This is the React version of `element.style.height = ...` in vanilla JS.
**Use it only for dynamic values** — static styling stays in Tailwind classes.

## (c) Real CSS in a file — complex things Tailwind can't express (keyframes, complex selectors)

**Global CSS** (`src/app/globals.css`) — animations/utilities used app-wide:
```css
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-10px); }
}
.animate-float { animation: float 3s ease-in-out infinite; }
```
```jsx
<div className="animate-float">🎈</div>   {/* now usable as a class */}
```

**CSS Modules** (`Hero.module.css`) — custom CSS scoped to ONE component (won't leak):
```css
/* Hero.module.css */
.glow { box-shadow: 0 0 40px 10px rgba(79,70,229,0.4); }
```
```jsx
import styles from "./Hero.module.css";
<div className={styles.glow} />
```
> This is the closest thing to your old `style.css` — but scoped to one component, so class names never collide. (In vanilla, `.glow` was global and could clash; a CSS Module auto-renames it behind the scenes.)

## (d) Extend the theme — make custom things into reusable utilities
If a custom color/font/animation is used *often*, add it to the Tailwind theme (in `globals.css` for v4) so it becomes a first-class utility like `bg-brand` or `animate-float`. Your standards **require** shared design tokens for colors, so brand colors belong here.

## Which to reach for

| Situation | Use |
|---|---|
| Odd one-off px/color | arbitrary value `w-[327px]` |
| Value from data/state at runtime | inline `style={{}}` |
| Keyframe animation, complex selector | CSS in `globals.css` or a `.module.css` |
| Custom color/font used everywhere | extend the theme (token) |
| A whole custom-looking block reused | **make it a component** (best default) |

> **React instinct:** before writing custom CSS, ask *"can I just make this a component and style it with utilities?"* — usually yes.

---

# 2. Page composition — components inside components

**A page is a tree of components. Sections are components. Sections contain smaller components.** That's exactly how it works.

## A real page, laid out
```
HomePage (page.tsx)
├── <Navbar />
├── <Hero />
│     ├── <Button />        (reused)
│     └── <Button />
├── <FeatureGrid />
│     ├── <FeatureCard />   (same component, ×3)
│     ├── <FeatureCard />
│     └── <FeatureCard />
├── <BlogSection />
│     └── <BlogCard />      (×N, from data)
└── <Footer />
```

## The page file just *composes* the sections
```jsx
// src/app/page.tsx
import { Navbar } from "@/components/navbar";
import { Hero } from "@/components/hero";
import { FeatureGrid } from "@/components/feature-grid";
import { BlogSection } from "@/components/blog-section";
import { Footer } from "@/components/footer";

export default function HomePage() {
  return (
    <main>
      <Navbar />
      <Hero />
      <FeatureGrid />
      <BlogSection />
      <Footer />
    </main>
  );
}
```
Each section is its own file with its own markup + Tailwind, and a section can render smaller components (like `FeatureCard`) — nesting.

> **Compared to what you know:** in Bootstrap you'd write one giant `index.html` with all sections stacked. React gives the same *visual result* but **splits that one big file into many small files that assemble back together.** Same page — organized so each piece is editable and reusable in isolation.

## When do you make something a component (vs just a `<div>`)?
Not every div is a component. Extract when:
1. **It repeats** — a card shown 3× → one `FeatureCard`, rendered 3×. (Never copy-paste markup.)
2. **A section is big enough to deserve its own file** — a 60-line hero → move to `Hero.tsx` so the page stays readable.
3. **It's reused across pages** — Navbar, Footer, Button.

If it's a one-off small thing used once, a plain `<div className="...">` inside the section is fine.
**Rule of thumb:** *repeats or getting big → component; otherwise → just an element.*

## For your HRIS specifically
```
EmployeesPage
├── <PageHeader title="Employees" />
├── <EmployeeFilters />        (search + dropdowns)
├── <EmployeeTable />          (uses shadcn <Table>)
│     └── <EmployeeRow />      (×N from the API)
└── <Pagination />
```
Same pattern — sections as components, repeated pieces as their own components, data-driven lists via `.map()`.

## Rendering a list from data (the `.map` pattern)
How "N cards from data" works — no copy-paste:
```jsx
{employees.map((emp) => (
  <EmployeeRow key={emp.id} employee={emp} />
))}
```
One `EmployeeRow` component, rendered once per row. (`key` must be unique — React uses it to track rows.)

---

# 3. Complex/interactive sections (e.g. a three.js background)

The part that feels impossible coming from vanilla. Let's rebuild the mental model.

## Why it's different in React
**In vanilla JS** you do something imperative:
```js
const canvas = document.getElementById("bg");
// ...set up three.js, then an animation loop
requestAnimationFrame(animate);
```
You grab the element and command it step by step.

**The problem in React:** React *owns and controls the DOM*. You don't `document.getElementById` and manually append — React re-renders and would wipe your changes. So React gives you two tools to bridge to imperative libraries:

- **`useRef`** = "give me a handle to this real DOM element" (React's `getElementById`).
- **`useEffect`** = "run this code *after* the component appears, and run cleanup when it disappears." (Like `DOMContentLoaded` for setup + `removeEventListener` for teardown, combined.)

## Way A — the clean way: a wrapper library (react-three-fiber)
Most big JS libraries have a React "wrapper." For three.js it's `@react-three/fiber` — write three.js *as components*, declaratively:
```jsx
"use client";
import { Canvas } from "@react-three/fiber";

function SpinningBox() {
  return (
    <mesh rotation={[0.5, 0.5, 0]}>
      <boxGeometry />
      <meshStandardMaterial color="#4F46E5" />
    </mesh>
  );
}

export function ThreeBackground() {
  return (
    <div className="absolute inset-0 -z-10">   {/* sits behind content */}
      <Canvas>
        <ambientLight />
        <SpinningBox />
      </Canvas>
    </div>
  );
}
```
No manual DOM, no animation loop to manage — the library handles it. **Recommended route.**

## Way B — the manual bridge (raw three.js via useRef + useEffect)
When there's no wrapper (or you want raw control), run imperative code inside `useEffect`:
```jsx
"use client";
import { useRef, useEffect } from "react";
import * as THREE from "three";

export function ThreeBackground() {
  const mountRef = useRef<HTMLDivElement>(null);   // handle to the container div

  useEffect(() => {
    const mount = mountRef.current!;
    const renderer = new THREE.WebGLRenderer({ antialias: true });
    renderer.setSize(mount.clientWidth, mount.clientHeight);
    mount.appendChild(renderer.domElement);        // now your vanilla-style code

    // ...scene, camera, mesh setup...
    let frameId: number;
    const animate = () => {
      // rotate, etc.
      // renderer.render(scene, camera);
      frameId = requestAnimationFrame(animate);
    };
    animate();

    // CLEANUP — runs when the component leaves the screen (prevents leaks)
    return () => {
      cancelAnimationFrame(frameId);
      renderer.dispose();
      mount.removeChild(renderer.domElement);
    };
  }, []);   // [] = run once on mount

  return <div ref={mountRef} className="absolute inset-0 -z-10" />;
}
```
> It's basically your **vanilla three.js code**, placed inside `useEffect`, targeting a `ref`'d element instead of `getElementById`, with a **cleanup function** returned (the thing vanilla often forgets — React makes it explicit so animations don't keep running after you navigate away).

## Drop it into a page like any other component
```jsx
<section className="relative min-h-screen">
  <ThreeBackground />                {/* absolute, -z-10 → behind */}
  <div className="relative z-10">    {/* content on top */}
    <Hero />
  </div>
</section>
```

## The generalization (key takeaway)
The `useRef` + `useEffect` pattern is **how you bridge ANY imperative JS library into React** — three.js, charting libs, Google Maps, video players, fancy date pickers. Either use a React wrapper (cleaner) or wrap the raw library in `useEffect`. You're not stuck; React just wants you to go through these two hooks so it can manage setup/teardown.

> **Relevant to you:** an HRIS won't need three.js — but it *will* need **charts** (headcount, leave trends). Same pattern: you'll use **Recharts** (a React chart lib, declarative like Way A). ⚠️ Adding libraries (three.js, react-three-fiber, Recharts) needs **CTO approval** per standards §0.5 — Recharts is pre-blessed as an example there.

---

# 4. The whole picture, assembled

| Concept | Vanilla / Bootstrap world | React / Next.js world |
|---|---|---|
| **Structure** | one big HTML file, sections stacked | a tree of component files that compose the page |
| **Static styling** | `style.css` with selectors | Tailwind utility classes on each element |
| **Custom styling** | more CSS rules | arbitrary values, CSS Modules, or extend the theme |
| **Reusable UI** | copy-paste HTML / Bootstrap classes | make a component (yours) or use shadcn |
| **Repeated items** | copy-paste, or JS building HTML strings | `data.map(item => <Card key={item.id} .../>)` |
| **Dynamic values** | `el.style.x = ...` | inline `style={{}}` or state |
| **Interactivity** | `addEventListener`, manual DOM | React state + event props (`onClick`) |
| **JS libraries (three.js, charts)** | grab canvas, run code | React wrapper lib, or `useRef` + `useEffect` bridge |

**The unifying idea:** you describe the UI as a **tree of components**; styling lives on the elements inside them (**Tailwind for the 95%, custom CSS for the rest**); and anything imperative gets **bridged in through hooks**. Once that clicks, every page — simple form or three.js showcase — is the same handful of patterns.

---

# 5. Bonus: Figma → Tailwind quick translation

| Figma gives you | Translate to | Example |
|---|---|---|
| Auto Layout (H/V) | `flex` / `flex-col` + `gap-*` | vertical, 24px gap → `flex flex-col gap-6` |
| Padding (48px) | `p-*` (÷4) | 48px → `p-12` |
| Gap (16px) | `gap-*` | 16px → `gap-4` |
| Font size (30px) | `text-*` | 30px → `text-3xl` |
| Font weight (Bold) | `font-*` | Bold → `font-bold` |
| Color (`#4F46E5`) | theme token or arbitrary | `bg-primary` or `bg-[#4F46E5]` |
| Corner radius (8px) | `rounded-*` | 8px → `rounded-lg` |
| Drop shadow | `shadow-*` | small → `shadow-sm` |

Two helpers:
- **Tailwind spacing scale = value ÷ 4.** `p-4`=16px, `p-6`=24px, `p-12`=48px. Most designs use multiples of 4/8 → clean mapping.
- **Exact one-off values** → square brackets: `w-[327px]`, `text-[13px]`, `bg-[#4F46E5]`.
- **Figma Auto Layout ≈ Flexbox** — direction→`flex-row/col`, gap→`gap-*`, alignment→`items-*`/`justify-*`, padding→`p-*`. Nearly 1:1.

---

## Related
- Stack overview + worked example: `HRIS-Tech-Stack-How-It-Works.md`
- Rules & conventions: `HRIS-AI-Build-Guide.md`
- Setup + build steps: `HRIS-Step-by-Step-Execution.md`
