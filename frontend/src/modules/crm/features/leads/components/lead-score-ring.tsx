type Props = {
  value: number;
  label?: string;
  size?: number;
};

export function LeadScoreRing({ value, label = "LEAD SCORE", size = 72 }: Props) {
  const stroke = 6;
  const radius = (size - stroke) / 2;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (value / 100) * circumference;

  return (
    <div
      className="inline-flex flex-col items-center"
      role="img"
      aria-label={`Lead score ${value} of 100`}
    >
      {/* Relative box sized to the ring so the score centers against the SVG itself,
          not the page/outer container — stays put on scroll, resize, and re-layout. */}
      <div className="relative" style={{ width: size, height: size }}>
        <svg
          width={size}
          height={size}
          className="-rotate-90"
          aria-hidden
        >
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke="var(--gray-150)"
            strokeWidth={stroke}
          />
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke="var(--teal-400)"
            strokeWidth={stroke}
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={offset}
            className="transition-all duration-500"
          />
        </svg>
        <span className="absolute inset-0 flex items-center justify-center text-xl font-extrabold text-card-foreground">
          {value}
        </span>
      </div>
      <span className="mt-2 text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {label}
      </span>
    </div>
  );
}
