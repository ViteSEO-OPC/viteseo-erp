"use client";

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from "react";

/**
 * Shared state for the global header search input.
 *
 * The header always renders a live, controlled input. A page may "register" to take over the term
 * — Leads filters its table from `value`; Dashboard sets an explicit `onSubmit`. Registering is an
 * OPT-IN OVERRIDE, not a prerequisite: on any route where no page registers, PageHeader falls back
 * to sending the query to the Leads list, so search works everywhere in the CRM.
 *
 * (It previously worked the other way round — the header only wired the input up when a page had
 * registered, leaving it inert on every other route.)
 */
type SubmitHandler = ((value: string) => void) | undefined;

type HeaderSearchContextValue = {
  value: string;
  setValue: (value: string) => void;
  registered: boolean;
  setRegistered: (registered: boolean) => void;
  // Optional per-page Enter/submit handler (e.g. Dashboard → navigate to /leads?search=).
  onSubmit: SubmitHandler;
  setOnSubmit: (handler: SubmitHandler) => void;
};

const HeaderSearchContext = createContext<HeaderSearchContextValue | undefined>(
  undefined,
);

export function HeaderSearchProvider({ children }: { children: ReactNode }) {
  const [value, setValue] = useState("");
  const [registered, setRegistered] = useState(false);
  const [onSubmit, setOnSubmitState] = useState<SubmitHandler>(undefined);

  const setOnSubmit = useCallback((handler: SubmitHandler) => {
    // Wrap so React stores the function rather than treating it as an updater.
    setOnSubmitState(() => handler);
  }, []);

  const context = useMemo(
    () => ({ value, setValue, registered, setRegistered, onSubmit, setOnSubmit }),
    [value, registered, onSubmit, setOnSubmit],
  );

  return (
    <HeaderSearchContext.Provider value={context}>
      {children}
    </HeaderSearchContext.Provider>
  );
}

export function useHeaderSearch() {
  const context = useContext(HeaderSearchContext);
  if (!context) {
    throw new Error("useHeaderSearch must be used within a HeaderSearchProvider");
  }
  return context;
}
