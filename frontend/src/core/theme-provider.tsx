"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";

type Theme = "light" | "dark";

type ThemeContextValue = {
  theme: Theme;
  setTheme: (theme: Theme) => void;
  toggleTheme: () => void;
};

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

export const THEME_STORAGE_KEY = "viteseo-theme";

/**
 * Platform-wide light/dark theme, applied to the shared <html> element so it is
 * visible EVERYWHERE — starting at the launcher (`/`), not buried inside a single
 * module. This is the general switch the CRM/Zerem module previously kept scoped
 * to itself.
 *
 * Mechanism (must match the pre-hydration script in app/layout.tsx):
 *   - `.dark` CLASS on <html> — what Tailwind's `dark:` utilities key on
 *     (`@custom-variant dark (&:is(.dark *))` in globals.css).
 *   - `data-theme="dark|light"` — flips the shared token ramp in globals.css
 *     (`.dark, [data-theme="dark"] { … }`) and the CRM ramp in crm-theme.css.
 *
 * Default = the device preference (`prefers-color-scheme`) until the user makes
 * an explicit choice, which is then persisted under a platform-namespaced key.
 */
function applyTheme(theme: Theme) {
  const el = document.documentElement;
  el.classList.toggle("dark", theme === "dark");
  el.setAttribute("data-theme", theme);
}

// The no-flash script (app/layout.tsx) has already resolved and painted the
// correct theme onto <html> before React hydrates, so we read the initial state
// straight from the DOM — no mount-effect reconciliation, no setState-in-effect.
function getInitialTheme(): Theme {
  if (typeof document === "undefined") return "light";
  return document.documentElement.classList.contains("dark") ? "dark" : "light";
}

function getInitialUserChose(): boolean {
  if (typeof localStorage === "undefined") return false;
  const stored = localStorage.getItem(THEME_STORAGE_KEY);
  return stored === "light" || stored === "dark";
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(getInitialTheme);
  const [userChose, setUserChose] = useState(getInitialUserChose);

  // Keep <html> in sync with state on every toggle (the initial paint is already
  // done by the no-flash script; this covers subsequent changes).
  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  // While the user hasn't chosen, follow live OS theme changes.
  useEffect(() => {
    if (userChose) return;
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = (e: MediaQueryListEvent) => setThemeState(e.matches ? "dark" : "light");
    mq.addEventListener("change", onChange);
    return () => mq.removeEventListener("change", onChange);
  }, [userChose]);

  // Persist ONLY on an explicit user change — never in a mount effect, which
  // would clobber the stored value with the default before the restore runs.
  const setTheme = useCallback((next: Theme) => {
    setUserChose(true);
    setThemeState(next);
    localStorage.setItem(THEME_STORAGE_KEY, next);
  }, []);

  const toggleTheme = useCallback(() => {
    setTheme(theme === "dark" ? "light" : "dark");
  }, [theme, setTheme]);

  return (
    <ThemeContext.Provider value={{ theme, setTheme, toggleTheme }}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme() {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error("useTheme must be used within a ThemeProvider");
  }
  return context;
}
