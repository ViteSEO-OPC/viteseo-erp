'use client';

import { useAuth, useIsManager } from '@/core/auth/AuthProvider';
import { Card } from '@/core/ui';
import { MemberTeamView } from './MemberTeamView';
import { ManagerTeamView } from './ManagerTeamView';

/**
 * TeamView — Routes to the appropriate view based on user role.
 * - Members see MemberTeamView (read-only)
 * - PM/Admin see ManagerTeamView (full management)
 */
export function TeamView() {
  const { authChecked } = useAuth();
  const isManager = useIsManager();

  // Show loading state while auth is being checked
  if (!authChecked) {
    return (
      <div className="px-4 py-4 sm:px-6 lg:px-8">
        <Card className="p-8 text-center">
          <p className="text-ink-soft">Loading…</p>
        </Card>
      </div>
    );
  }

  // Route based on role
  return isManager ? <ManagerTeamView /> : <MemberTeamView />;
}
