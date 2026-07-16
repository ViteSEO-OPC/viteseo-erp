"use client";

import {
  Eye,
  Pencil,
  Trash2,
  RotateCcw,
  BarChart3,
  Download,
  type LucideIcon,
} from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";

type Rule = {
  icon: LucideIcon;
  title: string;
  description: string;
  defaultChecked: boolean;
};

const RULES: Rule[] = [
  { icon: Eye, title: "Can view assigned leads", description: "See the leads assigned to them.", defaultChecked: true },
  { icon: Pencil, title: "Can edit assigned leads", description: "Update contact details, status, and notes.", defaultChecked: true },
  { icon: Trash2, title: "Can delete leads", description: "Deleted leads go to the Recycle Bin — never gone for good.", defaultChecked: true },
  { icon: RotateCcw, title: "Can restore deleted leads", description: "Bring leads back from the Recycle Bin.", defaultChecked: false },
  { icon: BarChart3, title: "Can view reports", description: "Open the Reports and BD Performance pages.", defaultChecked: true },
  { icon: Download, title: "Can export data", description: "Download leads and reports as CSV files.", defaultChecked: true },
];

const enabledCount = RULES.filter((r) => r.defaultChecked).length;

/**
 * Rules & Permissions — UI-first. Toggles are presentational for this milestone;
 * persisting them is deferred to the granular-permissions work.
 */
export function RulesPermissionsPage() {
  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Rules &amp; Permissions</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Choose what every BD account can do. Flip a switch, then save.
        </p>
      </header>

      <AlertBanner
        variant="info"
        message="These rules apply to all BD accounts. The Admin account always has full access."
      />

      <Card className="border border-border bg-card p-0 shadow-card">
        <div className="flex items-center gap-2 border-b border-border-subtle px-5 py-4">
          <h3 className="text-sm font-bold text-card-foreground">What BD accounts can do</h3>
          <span className="text-xs text-muted-foreground">
            {enabledCount} of {RULES.length} actions enabled
          </span>
        </div>
        <div className="divide-y divide-border-subtle">
          {RULES.map((rule) => {
            const Icon = rule.icon;
            return (
              <div key={rule.title} className="flex items-center justify-between gap-4 px-5 py-4">
                <div className="flex items-start gap-3">
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon className="h-4 w-4" aria-hidden />
                  </span>
                  <div>
                    <p className="text-sm font-semibold text-card-foreground">{rule.title}</p>
                    <p className="text-sm text-muted-foreground">{rule.description}</p>
                  </div>
                </div>
                <Switch defaultChecked={rule.defaultChecked} aria-label={rule.title} />
              </div>
            );
          })}
        </div>
      </Card>

      <div className="flex items-center justify-between">
        <p className="text-xs text-muted-foreground">Last saved Jul 1, 2026</p>
        <Button variant="default" size="sm">Save rules</Button>
      </div>
    </div>
  );
}
