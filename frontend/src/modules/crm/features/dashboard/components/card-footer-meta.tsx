type Props = {
  items: string[];
};

export function CardFooterMeta({ items }: Props) {
  return (
    <p className="mt-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
      {items.join(" · ")}
    </p>
  );
}
