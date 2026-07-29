import type { Metadata } from "next";
import { Exo_2 } from "next/font/google";
import "./globals.css";
import { QueryProvider } from "@/core/query-provider";
import { AuthProvider } from "@/core/auth-context";
import { Toaster } from "@/core/components/toaster";

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
    >
      <body className="min-h-full flex flex-col">
        <QueryProvider>
          {/* Realtime is deliberately NOT mounted here. The WebSocket layer is
              CRM-scoped and starts in src/app/zerem/layout.tsx, so HRIS,
              Sprintly and the rest of the platform open no socket and keep their
              existing behaviour. */}
          <AuthProvider>{children}</AuthProvider>
        </QueryProvider>
        <Toaster />
      </body>
    </html>
  );
}
