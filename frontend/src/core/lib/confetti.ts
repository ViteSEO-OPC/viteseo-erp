/**
 * Self-contained confetti celebration — no dependencies. Renders a short-lived
 * full-screen canvas burst (two bottom "cannons") and cleans itself up.
 *
 * Used when a task is marked done: the acting user fires it locally, and every
 * other viewer of the project fires it from the `TaskCompleted` broadcast.
 * Honours `prefers-reduced-motion`.
 */

const COLORS = [
  "#4fdbd7", // brand teal
  "#0f766e", // deep teal
  "#f59e0b", // amber
  "#6366f1", // indigo
  "#ef4444", // red
  "#10b981", // green
  "#ec4899", // pink
];

interface Particle {
  x: number;
  y: number;
  vx: number;
  vy: number;
  size: number;
  color: string;
  rot: number;
  vrot: number;
  opacity: number;
}

function prefersReducedMotion(): boolean {
  return (
    typeof window !== "undefined" &&
    !!window.matchMedia?.("(prefers-reduced-motion: reduce)").matches
  );
}

/** Launch one confetti burst. Safe to call repeatedly. */
export function fireConfetti(): void {
  if (typeof window === "undefined" || typeof document === "undefined") return;
  if (prefersReducedMotion()) return;

  const canvas = document.createElement("canvas");
  canvas.setAttribute("aria-hidden", "true");
  canvas.style.cssText =
    "position:fixed;inset:0;width:100vw;height:100vh;pointer-events:none;z-index:2147483647";
  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  const W = window.innerWidth;
  const H = window.innerHeight;
  canvas.width = W * dpr;
  canvas.height = H * dpr;
  ctx.scale(dpr, dpr);
  document.body.appendChild(canvas);

  const particles: Particle[] = [];
  const perCannon = 80;
  const cannons = [
    { x: W * 0.12, dir: 1 }, // bottom-left, fires up-right
    { x: W * 0.88, dir: -1 }, // bottom-right, fires up-left
  ];
  for (const c of cannons) {
    for (let i = 0; i < perCannon; i++) {
      // ~60° above horizontal, ±25° spread, aimed inward.
      const angle = (Math.PI / 3) * (0.7 + Math.random() * 0.6);
      const speed = 11 + Math.random() * 9;
      particles.push({
        x: c.x,
        y: H + 8,
        vx: Math.cos(angle) * speed * c.dir,
        vy: -Math.sin(angle) * speed,
        size: 5 + Math.random() * 7,
        color: COLORS[Math.floor(Math.random() * COLORS.length)],
        rot: Math.random() * Math.PI * 2,
        vrot: (Math.random() - 0.5) * 0.4,
        opacity: 1,
      });
    }
  }

  const GRAVITY = 0.32;
  const DRAG = 0.992;
  const start = performance.now();
  const DURATION = 3500;

  const tick = (now: number): void => {
    const elapsed = now - start;
    ctx.clearRect(0, 0, W, H);
    let alive = 0;

    for (const p of particles) {
      p.vx *= DRAG;
      p.vy = p.vy * DRAG + GRAVITY;
      p.x += p.vx;
      p.y += p.vy;
      p.rot += p.vrot;
      // Fade out over the last third of the animation.
      if (elapsed > DURATION * 0.66) {
        p.opacity = Math.max(0, p.opacity - 0.02);
      }
      if (p.y < H + 20 && p.opacity > 0) alive++;

      ctx.save();
      ctx.globalAlpha = p.opacity;
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      ctx.fillStyle = p.color;
      ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
      ctx.restore();
    }

    if (elapsed < DURATION && alive > 0) {
      requestAnimationFrame(tick);
    } else {
      canvas.remove();
    }
  };

  requestAnimationFrame(tick);
}

// Dedupe so the actor's local burst and an incoming broadcast for the same task
// don't stack into a double celebration.
const recent = new Map<string, number>();
const DEDUPE_MS = 5000;

/** Celebrate a specific task's completion, ignoring repeats within a window. */
export function celebrateTask(taskId: string): void {
  const now = Date.now();
  const last = recent.get(taskId);
  if (last && now - last < DEDUPE_MS) return;
  recent.set(taskId, now);
  // Keep the map from growing unbounded.
  if (recent.size > 50) {
    for (const [id, t] of recent) {
      if (now - t > DEDUPE_MS) recent.delete(id);
    }
  }
  fireConfetti();
}
