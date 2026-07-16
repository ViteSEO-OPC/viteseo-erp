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
 * Lets a page (e.g. Leads) drive the existing global header search input.
 * The header renders the input; a page "registers" to receive its value.
 * When no page is registered the header search behaves as an inert field
 * (unchanged from its default appearance on other routes).
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
