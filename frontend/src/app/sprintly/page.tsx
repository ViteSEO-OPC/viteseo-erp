'use client';

import { useAuth } from '@/core/auth/AuthProvider';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { DashboardView } from '@/modules/projects/components/dashboard/dashboard-view';
import {
  SkeletonCardGrid,
  SkeletonPage,
  SkeletonStatCards,
} from '@/modules/projects/components/shared/skeletons';

export default function ProjectsPage() {
  const { isAuthenticated, isLoading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push('/login');
    }
  }, [isAuthenticated, isLoading, router]);

  if (isLoading) {
    return (
      <SkeletonPage>
        <SkeletonStatCards />
        <SkeletonCardGrid className="mt-6" count={3} />
      </SkeletonPage>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  return <DashboardView />;
}
