'use client';

import {
  useForm,
  type FieldValues,
  type UseFormProps,
  type UseFormReturn,
} from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';

/**
 * Thin, fully-typed wrapper around react-hook-form's `useForm` that pre-wires
 * `zodResolver` for a given schema. Field types are inferred from the schema
 * (input for the form values, output for the submit handler), so it is a
 * drop-in for existing `register`/`handleSubmit`/`formState` usage.
 */
export function useValidatedForm<TSchema extends z.ZodType<FieldValues, FieldValues>>(
  schema: TSchema,
  options?: Omit<UseFormProps<z.input<TSchema>, unknown, z.output<TSchema>>, 'resolver'>,
): UseFormReturn<z.input<TSchema>, unknown, z.output<TSchema>> {
  return useForm<z.input<TSchema>, unknown, z.output<TSchema>>({
    ...options,
    resolver: zodResolver(schema),
  });
}
