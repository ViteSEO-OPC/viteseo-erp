import type { Metadata } from "next";
import { Exo_2 } from "next/font/google";
import "./globals.css";
import { QueryProvider } from "@/core/query-provider";
import { AuthProvider } from "@/core/auth-context";
import { ThemeProvider } from "@/core/theme-provider";
import { Toaster } from "@/core/components/toaster";

// Runs BEFORE first paint so the correct theme is already on <html> — no
// white flash on the dark-mode homepage. Reads the persisted choice, else the
// device preference. Must mirror src/core/theme-provider.tsx.
const themeScript = `(function(){try{var s=localStorage.getItem("viteseo-theme"),d=document.documentElement,dark=s?s==="dark":matchMedia("(prefers-color-scheme:dark)").matches;d.classList.toggle("dark",dark);d.setAttribute("data-theme",dark?"dark":"light");}catch(e){}})();`;

// Exo 2 — the Style Guide's single typeface (Bold headings, Medium nav, Regular body).
const exo2 = Exo_2({
  variable: "--font-exo2",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
});

export const metadata: Metadata = {
  title: "Vite SEO Systems",
  description: "Vite SEO Systems — HRIS, CRM & Project Management",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${exo2.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body className="min-h-full flex flex-col">
        <ThemeProvider>
          <QueryProvider>
            {/* Realtime is deliberately NOT mounted here. The WebSocket layer is
                CRM-scoped and starts in src/app/zerem/layout.tsx, so HRIS,
                Sprintly and the rest of the platform open no socket and keep their
                existing behaviour. */}
            <AuthProvider>{children}</AuthProvider>
          </QueryProvider>
          <Toaster />
        </ThemeProvider>
      </body>
    </html>
  );
}
