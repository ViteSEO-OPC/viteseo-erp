"use client";

import { Menu, Search } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import { ThemeToggle } from "@/modules/crm/components/layout/topbar/theme-toggle";
import { UserMenu } from "@/modules/crm/components/layout/user-menu/user-menu";
import { useHeaderSearch } from "@/modules/crm/providers/header-search-provider";

type PageHeaderProps = {
  title: string;
  onMenuClick?: () => void;
  className?: string;
};

export function PageHeader({ title, onMenuClick, className }: PageHeaderProps) {
  const { value, setValue, registered, onSubmit } = useHeaderSearch();
  return (
    <header
      className={cn(
        "flex items-center justify-between border-b border-border-subtle bg-background px-4 py-3 lg:px-6",
        className,
      )}
    >
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={onMenuClick}
          className="flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
          aria-label="Open navigation menu"
        >
          <Menu className="h-5 w-5" />
        </button>
        <h1 className="font-primary text-page-title font-bold text-card-foreground">
          {title}
        </h1>
      </div>

      <div className="flex items-center gap-2">
        <SearchInput
          className="hidden sm:block w-[240px]"
          value={registered ? value : undefined}
          onChange={registered ? setValue : undefined}
          onSubmit={registered ? onSubmit : undefined}
        />
        <button
          type="button"
          className="flex h-9 w-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:hidden"
          aria-label="Search"
        >
          <Search className="h-4 w-4" />
        </button>
        <ThemeToggle />
        <UserMenu />
      </div>
    </header>
  );
}
