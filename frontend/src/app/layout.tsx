import type { Metadata } from "next";
import { Exo_2 } from "next/font/google";
import "./globals.css";
import { QueryProvider } from "@/core/query-provider";
import { AuthProvider } from "@/core/auth-context";
import { RealtimeProvider } from "@/core/realtime/RealtimeProvider";
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
          {/* RealtimeProvider sits inside both: it reads the signed-in user from
              AuthProvider and patches the cache owned by QueryProvider. It stays
              inert until NEXT_PUBLIC_REVERB_APP_KEY is set, so environments
              without Reverb are unaffected. */}
          <AuthProvider>
            <RealtimeProvider>{children}</RealtimeProvider>
          </AuthProvider>
        </QueryProvider>
        <Toaster />
      </body>
    </html>
  );
}
