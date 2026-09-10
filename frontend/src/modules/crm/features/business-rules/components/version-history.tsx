"use client";

import { RotateCcw } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { Button } from "@/modules/crm/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import type {
  BusinessRuleState,
  BusinessRuleVersion,
} from "@/modules/crm/features/business-rules/types/business-rules-types";

function fmt(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString() : "—";
}

function stateBadge(state: BusinessRuleState) {
  if (state === "active") return <Badge variant="default">Active</Badge>;
  if (state === "draft") return <Badge variant="secondary">Draft</Badge>;
  return <Badge variant="outline">Archived</Badge>;
}

type Props = {
  versions: BusinessRuleVersion[];
  canManage: boolean;
  onRollback: (version: BusinessRuleVersion) => void;
};

/** Read-only, append-only version timeline. Archived versions offer a forward-only rollback. */
export function VersionHistory({ versions, canManage, onRollback }: Props) {
  if (versions.length === 0) {
    return <EmptyState message="No versions yet" />;
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-20">Version</TableHead>
            <TableHead className="w-28">State</TableHead>
            <TableHead>Reason</TableHead>
            <TableHead className="w-48">Created</TableHead>
            <TableHead className="w-48">Published</TableHead>
            <TableHead className="w-28 text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {versions.map((v) => (
            <TableRow key={v.id}>
              <TableCell className="font-mono tabular-nums">v{v.versionNumber}</TableCell>
              <TableCell>{stateBadge(v.state)}</TableCell>
              <TableCell className="text-sm text-muted-foreground">{v.reasonForChange ?? "—"}</TableCell>
              {/* A draft has no publishedAt, so without Created an unpublished row read entirely
                  blank. Both columns name their user when the endpoint resolved one. */}
              <TableCell className="text-sm text-muted-foreground">
                {fmt(v.createdAt)}
                {v.creator ? <span className="block text-xs">by {v.creator.name}</span> : null}
              </TableCell>
              <TableCell className="text-sm text-muted-foreground">
                {fmt(v.publishedAt)}
                {v.publisher ? <span className="block text-xs">by {v.publisher.name}</span> : null}
              </TableCell>
              <TableCell className="text-right">
                {canManage && v.state === "archived" ? (
                  <Button variant="outline" size="sm" onClick={() => onRollback(v)}>
                    <RotateCcw className="mr-1.5 h-3.5 w-3.5" /> Roll back
                  </Button>
                ) : null}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
