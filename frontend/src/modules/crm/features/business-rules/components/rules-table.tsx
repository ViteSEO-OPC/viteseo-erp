"use client";

import { useState } from "react";
import { ArrowDown, ArrowUp } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Input } from "@/modules/crm/components/ui/input";
import { Switch } from "@/modules/crm/components/ui/switch";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import {
  CATEGORY_LABEL,
  type BusinessRule,
} from "@/modules/crm/features/business-rules/types/business-rules-types";

type Props = {
  rules: BusinessRule[];
  /** When false the table is read-only (viewing the active version); when true it edits the draft. */
  editable: boolean;
  busy?: boolean;
  onUpdatePoints?: (ruleKey: string, pointValue: number) => void;
  onToggleEnabled?: (ruleKey: string, enabled: boolean) => void;
  onMove?: (ruleKey: string, direction: "up" | "down") => void;
};

/** Render the point value with its category-derived sign (magnitude only in storage; §1.2). */
function signed(rule: BusinessRule): string {
  return `${rule.isSubtractive ? "−" : "+"}${rule.pointValue}`;
}

function categoryVariant(category: BusinessRule["category"]) {
  if (category === "negative") return "destructive" as const;
  if (category === "fit") return "secondary" as const;
  return "outline" as const;
}

/**
 * Whether this rule set has scoring semantics — i.e. whether the Category and Points columns mean
 * anything. Derived from the data rather than passed in, because the data is the authority: the
 * backend sends both as null for the KPI catalog (a metric has no sign and is not worth points) and
 * always sends both for lead_scoring. Rendering a "—" in those columns instead of omitting them
 * would imply a KPI has an empty score; it has no score at all.
 */
function hasScoring(rules: BusinessRule[]): boolean {
  return rules.some((rule) => rule.category !== null || rule.pointValue !== null);
}

/** One editable row — keeps local input state so typing does not fire a request per keystroke. */
function EditableRow({
  rule,
  index,
  count,
  busy,
  scoring,
  onUpdatePoints,
  onToggleEnabled,
  onMove,
}: {
  rule: BusinessRule;
  index: number;
  count: number;
  busy?: boolean;
  /** False for the KPI catalog — the Category and Points columns are not rendered at all. */
  scoring: boolean;
  onUpdatePoints?: (ruleKey: string, pointValue: number) => void;
  onToggleEnabled?: (ruleKey: string, enabled: boolean) => void;
  onMove?: (ruleKey: string, direction: "up" | "down") => void;
}) {
  // Local input state; re-sync to the server value when it changes (e.g. after a save refetch) using the
  // React-recommended "adjust state during render" pattern rather than an effect.
  const [value, setValue] = useState(String(rule.pointValue ?? ""));
  const [syncedFrom, setSyncedFrom] = useState(rule.pointValue);
  if (rule.pointValue !== syncedFrom) {
    setSyncedFrom(rule.pointValue);
    setValue(String(rule.pointValue ?? ""));
  }

  const commit = () => {
    const next = Number(value);
    if (!Number.isInteger(next) || next < 0) {
      setValue(String(rule.pointValue ?? "")); // structural guard; the server is the authority.
      return;
    }
    if (next !== rule.pointValue) onUpdatePoints?.(rule.ruleKey, next);
  };

  return (
    <TableRow className={cn(!rule.isEnabled && "opacity-60")}>
      <TableCell className="w-16">
        <div className="flex flex-col">
          <button
            type="button"
            aria-label="Move up"
            disabled={busy || index === 0}
            onClick={() => onMove?.(rule.ruleKey, "up")}
            className="text-muted-foreground hover:text-foreground disabled:opacity-30"
          >
            <ArrowUp className="h-3.5 w-3.5" />
          </button>
          <button
            type="button"
            aria-label="Move down"
            disabled={busy || index === count - 1}
            onClick={() => onMove?.(rule.ruleKey, "down")}
            className="text-muted-foreground hover:text-foreground disabled:opacity-30"
          >
            <ArrowDown className="h-3.5 w-3.5" />
          </button>
        </div>
      </TableCell>
      <TableCell>
        <p className="font-medium text-foreground">{rule.description}</p>
        <p className="font-mono text-[11px] text-muted-foreground">{rule.ruleKey}</p>
      </TableCell>
      {scoring && (
        <>
          <TableCell>
            {rule.category && (
              <Badge variant={categoryVariant(rule.category)}>{CATEGORY_LABEL[rule.category]}</Badge>
            )}
          </TableCell>
          <TableCell className="w-32">
            <div className="flex items-center gap-1">
              <span className="text-muted-foreground">{rule.isSubtractive ? "−" : "+"}</span>
              <Input
                type="number"
                min={0}
                value={value}
                disabled={busy}
                onChange={(e) => setValue(e.target.value)}
                onBlur={commit}
                onKeyDown={(e) => e.key === "Enter" && e.currentTarget.blur()}
                className="h-8 w-20"
              />
            </div>
          </TableCell>
        </>
      )}
      <TableCell className="w-24 text-right">
        <Switch
          checked={rule.isEnabled}
          disabled={busy}
          onCheckedChange={(checked) => onToggleEnabled?.(rule.ruleKey, checked)}
        />
      </TableCell>
    </TableRow>
  );
}

export function RulesTable({
  rules,
  editable,
  busy,
  onUpdatePoints,
  onToggleEnabled,
  onMove,
}: Props) {
  const ordered = [...rules].sort((a, b) => a.displayOrder - b.displayOrder);
  const scoring = hasScoring(rules);

  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-16">Order</TableHead>
            <TableHead>Rule</TableHead>
            {scoring && (
              <>
                <TableHead>Category</TableHead>
                <TableHead className="w-32">Points</TableHead>
              </>
            )}
            <TableHead className="w-24 text-right">Enabled</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {ordered.map((rule, index) =>
            editable ? (
              <EditableRow
                key={rule.ruleKey}
                rule={rule}
                index={index}
                count={ordered.length}
                busy={busy}
                scoring={scoring}
                onUpdatePoints={onUpdatePoints}
                onToggleEnabled={onToggleEnabled}
                onMove={onMove}
              />
            ) : (
              <TableRow key={rule.ruleKey} className={cn(!rule.isEnabled && "opacity-60")}>
                <TableCell className="w-16 text-muted-foreground">{index + 1}</TableCell>
                <TableCell>
                  <p className="font-medium text-foreground">{rule.description}</p>
                  <p className="font-mono text-[11px] text-muted-foreground">{rule.ruleKey}</p>
                </TableCell>
                {scoring && (
                  <>
                    <TableCell>
                      {rule.category && (
                        <Badge variant={categoryVariant(rule.category)}>
                          {CATEGORY_LABEL[rule.category]}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="w-32 font-mono tabular-nums">{signed(rule)}</TableCell>
                  </>
                )}
                <TableCell className="w-24 text-right">
                  {rule.isEnabled ? (
                    <Badge variant="secondary">Enabled</Badge>
                  ) : (
                    <Badge variant="outline">Disabled</Badge>
                  )}
                </TableCell>
              </TableRow>
            ),
          )}
        </TableBody>
      </Table>
    </div>
  );
}
