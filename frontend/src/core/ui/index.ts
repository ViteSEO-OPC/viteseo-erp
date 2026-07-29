// Button
export { Button, buttonVariants } from './button';
export type { ButtonProps } from './button';

// Card (shadcn compositional parts available for new usage)
export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
} from './card';

// Badge (brand tones)
export { Badge, PriorityBadge, badgeVariants } from './badge';
export type { BadgeProps, BadgeTone } from './badge';

// Avatar — convenience (name-based) API + shadcn/Radix primitives
export { Avatar, AvatarStack } from './user-avatar';
export type { AvatarProps } from './user-avatar';
export {
  Avatar as AvatarRoot,
  AvatarImage,
  AvatarFallback,
  AvatarGroup,
  AvatarGroupCount,
  AvatarBadge,
} from './avatar';

// Progress
export { Progress, ProgressBar } from './progress';

// Stat card
export { StatCard } from './stat-card';
export type { StatCardProps } from './stat-card';

// Inputs
export { Input } from './input';
export { Textarea } from './textarea';
export { Label } from './label';
export { Field } from './field';

// Select — native drop-in (`Select`) + shadcn/Radix compositional (`SelectRoot`, …)
export { NativeSelect as Select } from './native-select';
export {
  Select as SelectRoot,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectScrollDownButton,
  SelectScrollUpButton,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from './select';

// Segmented control — pill toggle group (view switcher / scope filters)
export { SegmentedControl, SegmentedItem } from './segmented-control';

// Tabs — underline bar convenience API + shadcn/Radix primitives
export { Tabs } from './segmented-tabs';
export {
  Tabs as TabsRoot,
  TabsList,
  TabsTrigger,
  TabsContent,
} from './tabs';

// Dialog / Modal
export { Modal } from './modal';
export type { ModalProps } from './modal';
export { ConfirmDialog } from './confirm-dialog';
export type { ConfirmDialogProps } from './confirm-dialog';
export {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogOverlay,
  DialogPortal,
  DialogTitle,
  DialogTrigger,
} from './dialog';

// Misc
export { PagePlaceholder } from './page-placeholder';
export * from './icons';
export * from './sidebar-icons';
