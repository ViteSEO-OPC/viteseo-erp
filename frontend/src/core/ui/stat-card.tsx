import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';

import { cn } from '@/core/lib/utils';
import { Card } from './card';

export interface StatCardProps {
  label: string;
  value: string | number;
  delta?: { value: string; positive?: boolean };
  /**
   * Optional destination. When set, the whole card becomes a Next.js `<Link>`.
   * Mutually exclusive with `onClick` — if both are given, `href` wins.
   */
  href?: string;
  /**
   * Optional click handler. When set (and no `href`), the card renders as a
   * clickable `<button>`. Useful for switching a local tab instead of routing.
   */
  onClick?: () => void;
}

// Hover affordance shared by the linked/clickable variants.
const INTERACTIVE =
  'group cursor-pointer transition hover:border-brand/40 hover:shadow-md hover:-translate-y-0.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';

function StatCardBody({ label, value, delta }: Omit<StatCardProps, 'href' | 'onClick'>) {
  return (
    <>
      <p className="text-sm text-ink-soft">{label}</p>
      <div className="mt-3 flex items-end justify-between">
        <span className="text-3xl font-bold leading-none text-ink sm:text-4xl">
          {value}
        </span>
        {delta && (
          <span
            className={`text-sm font-bold ${delta.positive ? 'text-[#0f8f8b]' : 'text-danger'}`}
          >
            {delta.value}
          </span>
        )}
      </div>
    </>
  );
}

export function StatCard({ label, value, delta, href, onClick }: StatCardProps) {
  const interactive = Boolean(href) || Boolean(onClick);

  if (!interactive) {
    return (
      <Card className="gap-0 p-5">
        <StatCardBody label={label} value={value} delta={delta} />
      </Card>
    );
  }

  // Arrow affordance shown on hover/focus for interactive cards.
  const arrow = (
    <span className="absolute right-4 top-4 flex h-6 w-6 items-center justify-center rounded-full bg-primary">
      <ArrowUpRight
        aria-hidden
        className="h-4 w-4"
      />
    </span>
  );

  if (href) {
    return (
      <Link href={href} aria-label={`${label}: ${value}`} className="block">
        <Card className={cn('relative gap-0 p-5', INTERACTIVE)}>
            {arrow}
          <StatCardBody label={label} value={value} delta={delta} />
        </Card>
      </Link>
    );
  }

  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={`${label}: ${value}`}
      className="block w-full text-left"
    >
      <Card className={cn('relative gap-0 p-5', INTERACTIVE)}>
        {arrow}
        <StatCardBody label={label} value={value} delta={delta} />
      </Card>
    </button>
  );
}
