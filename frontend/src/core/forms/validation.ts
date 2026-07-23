import { z } from 'zod';

/**
 * Shared zod schema primitives — the single source of truth for the app's form
 * validation rules. Compose these into per-form object schemas instead of
 * re-declaring ad-hoc rules in every modal.
 */

/** A required, whitespace-trimmed string with a configurable message and max length. */
export function requiredTrimmedString(message: string, max = 255): z.ZodString {
  return z.string().trim().min(1, message).max(max);
}

/** A required (non-empty) string that is not trimmed — for select/id values. */
export function requiredString(message: string): z.ZodString {
  return z.string().min(1, message);
}

/** Project/workspace/group display name: required, trimmed, 1–255 chars. */
export const nameSchema = requiredTrimmedString('Name is required');

/** Optional free-text description: trimmed, max 2000 chars. */
export const descriptionSchema = z.string().trim().max(2000).optional();

/** A 6-digit hex color, e.g. `#6366f1`. */
export const hexColorSchema = z.string().regex(/^#([0-9a-fA-F]{6})$/, 'Pick a color');

/** Email address used across auth forms. */
export const emailSchema = z.string().email('Invalid email address');

/** Account password (registration): at least 8 characters. */
export const passwordSchema = z.string().min(8, 'Password must be at least 8 characters');

/** Project/task priority (lowercase variant used by project forms). */
export const priorityEnum = z.enum(['low', 'medium', 'high']);

/** Task priority (title-case variant used by the task form). */
export const taskPriorityEnum = z.enum(['Low', 'Medium', 'High']);

/** User theme preference. */
export const themeEnum = z.enum(['light', 'dark', 'system']);

/**
 * Shared object schemas — exported where several forms use an identical field
 * set, or where a form's schema is worth centralizing.
 */

/** Create/Edit Project forms. */
export const projectSchema = z.object({
  name: nameSchema,
  description: descriptionSchema,
  priority: priorityEnum,
  color: hexColorSchema,
});

/** Edit Workspace form. */
export const workspaceSchema = z.object({
  name: nameSchema,
  description: descriptionSchema,
});

/**
 * Create Group form. A group isn't tied to a project — it's a reusable roster of
 * people; membership drives which projects it belongs to. Members are picked
 * directly and the leader must be one of the selected members.
 */
export const groupSchema = z
  .object({
    name: nameSchema,
    color: hexColorSchema,
    // User ids kept as strings in the form; converted to numbers on submit.
    member_ids: z.array(z.string()).min(1, 'Select at least one member'),
    leader_id: requiredString('Leader is required'),
  })
  .superRefine((val, ctx) => {
    if (val.leader_id && !val.member_ids.includes(val.leader_id)) {
      ctx.addIssue({
        code: 'custom',
        path: ['leader_id'],
        message: 'The leader must be one of the selected members',
      });
    }
  });

/** Edit Group form — name / color / leader only (members managed separately). */
export const editGroupSchema = z.object({
  name: nameSchema,
  color: hexColorSchema,
  leader_id: requiredString('Leader is required'),
});

/** Create Task form. Individual tasks may be unassigned; groups require a group. */
export const createTaskSchema = z
  .object({
    title: requiredTrimmedString('Task name is required'),
    description: descriptionSchema,
    assignment_type: z.enum(['individual', 'group']),
    assignee_id: z.string().optional(),
    group_id: z.string().optional(),
    status_id: requiredString('Select a status'),
    priority: taskPriorityEnum,
    deadline: z.string().optional(),
    start_date: z.string().optional(),
  })
  .superRefine((val, ctx) => {
    // Individual tasks may be left unassigned (default); groups still require one.
    if (val.assignment_type === 'group' && !val.group_id) {
      ctx.addIssue({ code: 'custom', path: ['group_id'], message: 'Select a group' });
    }
    // Date rules (ISO YYYY-MM-DD strings compare lexicographically = chronologically).
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
    if (val.start_date && val.start_date < todayStr) {
      ctx.addIssue({ code: 'custom', path: ['start_date'], message: 'The start date can’t be in the past.' });
    }
    if (val.deadline && val.deadline < todayStr) {
      ctx.addIssue({ code: 'custom', path: ['deadline'], message: 'The due date can’t be in the past.' });
    }
    if (val.deadline && val.start_date && val.deadline < val.start_date) {
      ctx.addIssue({ code: 'custom', path: ['deadline'], message: 'Due date must be on or after the start date.' });
    }
  });

/** Login form. */
export const loginSchema = z.object({
  email: emailSchema,
  password: requiredString('Password is required'),
});

/** Inferred value type for the login form. */
export type LoginFormValues = z.infer<typeof loginSchema>;

/** Registration form. */
export const registerSchema = z
  .object({
    name: requiredString('Name is required'),
    email: emailSchema,
    password: passwordSchema,
    password_confirmation: requiredString('Please confirm your password'),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Passwords do not match',
    path: ['password_confirmation'],
  });

/** Inferred value type for the registration form. */
export type RegisterFormValues = z.infer<typeof registerSchema>;

/** Profile edit form (no stricter-than-before rules; preserves existing behavior). */
export const profileSchema = z.object({
  name: z.string(),
  avatar: z.string(),
  theme: themeEnum,
});
