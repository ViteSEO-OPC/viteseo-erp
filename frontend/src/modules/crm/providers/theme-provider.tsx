"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";

type Theme = "light" | "dark";

type ThemeContextValue = {
  theme: Theme;
  setTheme: (theme: Theme) => void;
};

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

const STORAGE_KEY = "zerem-theme";

/**
 * Zerem-only theme context. The theme is applied to a Zerem-OWNED wrapper element
 * (`.zerem-theme-scope`, with `data-theme`), NOT the shared `<html>` — so the dark state can never
 * leak into HRIS / Sprintly / Admin / the launcher, and it unmounts cleanly when the user leaves
 * `/zerem/*`. The matching dark CSS variables live in the module-local, Zerem-only
 * `styles/zerem-theme.css` (imported solely from the Zerem layout). No shared asset is touched.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setThemeState] = useState<Theme>("light");

  // Restore the persisted choice on mount. Done in an effect (not the useState initializer) so the
  // server-rendered markup and the first client render agree — avoiding a hydration mismatch. The
  // key is Zerem-namespaced so it can't collide with any other app's storage.
  useEffect(() => {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === "light" || stored === "dark") {
      setThemeState(stored);
    }
  }, []);

  // Persist ONLY on an explicit user change — never in a mount effect, which would clobber the stored
  // value with the "light" default before/while the restore runs (and re-clobber under StrictMode's
  // double-invoked effects). The theme is applied via the wrapper's `data-theme` below, deliberately
  // not the shared document element.
  const setTheme = useCallback((next: Theme) => {
    setThemeState(next);
    localStorage.setItem(STORAGE_KEY, next);
  }, []);

  return (
    <ThemeContext.Provider value={{ theme, setTheme }}>
      {/* BOTH hooks are required, and they drive different mechanisms:
          - `data-theme` flips the CRM token ramp (`crm-theme.css` → `.dark, [data-theme="dark"]`)
            and the Zerem-local shadcn surface tokens (`styles/zerem-theme.css`).
          - the `dark` CLASS is what Tailwind's `dark:` utilities key on — the shared variant is
            declared as `@custom-variant dark (&:is(.dark *))` in globals.css. Without it the ~74
            `dark:` corrections across the CRM (badges/chips/inputs) never fire and render
            dark-on-dark. Applying it HERE rather than on <html> keeps the match scoped to this
            subtree, so no other app is affected. */}
      <div className={theme === "dark" ? "zerem-theme-scope dark" : "zerem-theme-scope"} data-theme={theme}>
        {children}
      </div>
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
