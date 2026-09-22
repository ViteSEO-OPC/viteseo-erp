"use client";

import { SelectGroup, SelectItem, SelectLabel } from "@/modules/crm/components/ui/select";
import { ADD_CUSTOM_OPTION } from "@/modules/crm/features/email-templates/lib/template-options";
import type { EmailTemplateCatalog } from "@/modules/crm/features/email-templates/types/email-template-types";

/**
 * The custom part of the Purpose and Tone dropdowns in the template editors: this organization's custom values, in
 * their own group so they read apart from the built-in ones, then "+ Add custom …". Rendered inside a SelectContent;
 * the editor treats the ADD_CUSTOM_OPTION value as "open the add dialog", never as a selection.
 */

/** The tones the catalog ships with — every tone that is not one of this organization's custom tones. */
export function systemTones(catalog: EmailTemplateCatalog): string[] {
  const custom = new Set((catalog.customTones ?? []).map((tone) => tone.value));
  return catalog.tones.filter((tone) => !custom.has(tone));
}

const ADD_ITEM_CLASS = "font-medium text-teal-700 dark:text-teal-400";

export function CustomPurposeItems({ catalog }: { catalog: EmailTemplateCatalog }) {
  const custom = catalog.purposes.filter((purpose) => purpose.custom);

  return (
    <>
      {custom.length > 0 ? (
        <SelectGroup>
          <SelectLabel>Custom purposes</SelectLabel>
          {custom.map((purpose) => (
            <SelectItem key={purpose.value} value={purpose.value}>
              {purpose.value}
            </SelectItem>
          ))}
        </SelectGroup>
      ) : null}
      <SelectGroup>
        <SelectItem value={ADD_CUSTOM_OPTION} className={ADD_ITEM_CLASS}>
          + Add custom purpose
        </SelectItem>
      </SelectGroup>
    </>
  );
}

export function CustomToneItems({ catalog }: { catalog: EmailTemplateCatalog }) {
  const custom = catalog.customTones ?? [];

  return (
    <>
      {custom.length > 0 ? (
        <SelectGroup>
          <SelectLabel>Custom tones</SelectLabel>
          {custom.map((tone) => (
            <SelectItem key={tone.value} value={tone.value}>
              {tone.value}
            </SelectItem>
          ))}
        </SelectGroup>
      ) : null}
      <SelectGroup>
        <SelectItem value={ADD_CUSTOM_OPTION} className={ADD_ITEM_CLASS}>
          + Add custom tone
        </SelectItem>
      </SelectGroup>
    </>
  );
}
