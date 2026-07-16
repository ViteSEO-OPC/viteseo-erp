"use client";

import { useRef, type ChangeEvent } from "react";
import { Upload } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";

type Props = {
  onFile?: (file: File) => void;
  disabled?: boolean;
};

export function UploadDropzone({ onFile, disabled }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);

  const handleClick = () => inputRef.current?.click();

  const handleChange = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) onFile?.(file);
  };

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={disabled}
      className="flex w-full cursor-pointer flex-col items-center gap-3 rounded-xl border-2 border-dashed border-teal-400 bg-gray-50 px-6 py-8 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
      aria-label="Upload CSV or XLSX file"
    >
      <input
        ref={inputRef}
        type="file"
        accept=".csv,.xlsx"
        onChange={handleChange}
        className="hidden"
        aria-hidden
      />

      <span className="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--dropzone-icon-tint)]">
        <Upload className="h-5 w-5 text-teal-400" />
      </span>

      <div className="text-center">
        <p className="text-sm font-bold text-card-foreground">
          Drag & drop your file here
        </p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          or click to browse
        </p>
      </div>

      <div className="flex items-center gap-2">
        <Badge variant="outline" className="border-gray-200 bg-white text-xs font-medium text-foreground">
          CSV
        </Badge>
        <Badge variant="outline" className="border-gray-200 bg-white text-xs font-medium text-foreground">
          XLSX
        </Badge>
      </div>
    </button>
  );
}
