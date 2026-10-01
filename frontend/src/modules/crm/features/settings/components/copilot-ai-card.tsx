"use client";

import { AlertTriangle, CheckCircle2, Sparkles } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import {
  readinessLabel,
  useCopilotAiSetting,
  useUpdateCopilotAiSetting,
  type CopilotAiSetting,
} from "@/modules/crm/features/settings/hooks/use-copilot-ai-setting";

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * Settings → Integrations → Copilot AI. Whether the Copilot may use AI for questions it has no
 * standard answer to.
 *
 * SUPER ADMIN ONLY on the server (`role:super_admin`); a CRM Admin sees the explanation instead,
 * the same boundary the Nearby card draws, and for the same reason: the AI spends ViteSEO's own
 * credit. The provider credential is NOT configured here — it is the Nearby card's OpenAI/OpenRouter
 * key, and switching this on never changes it.
 *
 * ON IS NOT RUNNING. The switch is one of three gates (ops switch, this toggle, the credential), so the
 * chip reads "Active" only when the server reports every gate passed, and the readiness line names
 * whichever gate is holding it back.
 */
export function CopilotAiCard() {
  const { setting, isLoading, isError, isForbidden, retry } = useCopilotAiSetting();

  return (
    <Card className={cardClass}>
      <div className="flex flex-col gap-5">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted">
              <Sparkles className="size-4 text-teal-700 dark:text-teal-400" aria-hidden />
            </span>
            <div>
              <h3 className="text-base font-semibold text-foreground">Copilot AI</h3>
              <p className="mt-0.5 text-sm text-muted-foreground">
                Optional. Lets the Copilot answer questions it has no standard answer to, from your
                lead counts, using the Nearby OpenAI/OpenRouter key.
              </p>
            </div>
          </div>
          {setting ? <StatusChip setting={setting} /> : null}
        </header>

        {isLoading ? (
          <Skeleton className="h-16 w-full" aria-busy />
        ) : isForbidden ? (
          <p className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
            Copilot AI is set by the platform administrator, not per organisation — the AI account
            is bought and paid for centrally.
          </p>
        ) : isError || !setting ? (
          <ErrorState message="Could not load the Copilot AI setting." onRetry={() => void retry()} />
        ) : (
          <Body setting={setting} />
        )}
      </div>
    </Card>
  );
}

function StatusChip({ setting }: { setting: CopilotAiSetting }) {
  if (setting.aiAvailable) {
    return (
      <Badge className="gap-1 bg-teal-600 text-white dark:bg-teal-700">
        <CheckCircle2 className="size-3" aria-hidden />
        Active
      </Badge>
    );
  }
  if (setting.aiEnabled) {
    return (
      <Badge className="gap-1 border-amber-300 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
        <AlertTriangle className="size-3" aria-hidden />
        On, not running
      </Badge>
    );
  }
  return (
    <Badge variant="outline" className="border-border text-muted-foreground">
      Off
    </Badge>
  );
}

function Body({ setting }: { setting: CopilotAiSetting }) {
  const update = useUpdateCopilotAiSetting();

  return (
    <>
      <div className="flex items-center justify-between gap-4 rounded-lg border border-border p-4">
        <div>
          <p className="text-sm font-medium text-foreground">Allow the Copilot to use AI</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Off: the Copilot works exactly as before. On: questions it has no standard answer to may
            get an AI reply, marked “AI-assisted”. Commands, lead lookups and quick actions never use
            AI.
          </p>
        </div>
        <Switch
          checked={setting.aiEnabled}
          disabled={update.isPending}
          aria-label="Allow the Copilot to use AI"
          onCheckedChange={(checked) => update.mutate(checked)}
        />
      </div>

      <p
        className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
        role="status"
      >
        <span className="font-medium text-foreground">Readiness: </span>
        {readinessLabel(setting.readiness)}
      </p>
    </>
  );
}
