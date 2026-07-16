import { Card } from './card';

export function PagePlaceholder({ title, blurb }: { title: string; blurb: string }) {
  return (
    <div className="mx-auto max-w-[1180px] px-8 py-8">
      <h1 className="text-4xl font-bold text-ink">{title}</h1>
      <Card className="mt-8 gap-0 p-12 text-center">
        <p className="text-lg font-bold text-ink">Coming soon</p>
        <p className="mt-2 text-ink-soft">{blurb}</p>
      </Card>
    </div>
  );
}
