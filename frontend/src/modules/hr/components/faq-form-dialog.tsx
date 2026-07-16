"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { useCreateFaq, useUpdateFaq } from "@/modules/hr/hooks/use-faqs";
import { FAQ_CATEGORIES, type Faq } from "@/modules/hr/types/faq";

const selectClass =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring";

export function FaqFormDialog({
  open,
  onOpenChange,
  faq,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  faq?: Faq | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {open && <FormBody key={faq?.id ?? "new"} faq={faq ?? null} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function FormBody({ faq, onDone }: { faq: Faq | null; onDone: () => void }) {
  const isEdit = !!faq;
  const createMut = useCreateFaq();
  const updateMut = useUpdateFaq();

  const [question, setQuestion] = useState(faq?.question ?? "");
  const [answer, setAnswer] = useState(faq?.answer ?? "");
  const [category, setCategory] = useState(faq?.category ?? "general");
  const [tags, setTags] = useState((faq?.tags ?? []).join(", "));
  const [pinned, setPinned] = useState(faq?.is_pinned ?? false);

  const pending = createMut.isPending || updateMut.isPending;

  async function submit() {
    if (!question.trim() || !answer.trim()) return;
    const input = {
      question: question.trim(),
      answer: answer.trim(),
      category,
      tags: tags.split(",").map((t) => t.trim()).filter(Boolean),
      is_pinned: pinned,
    };
    try {
      if (faq) {
        await updateMut.mutateAsync({ id: faq.id, input });
        toast.success("FAQ updated");
      } else {
        await createMut.mutateAsync(input);
        toast.success("FAQ created (draft)");
      }
      onDone();
    } catch {
      toast.error("Could not save the FAQ.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isEdit ? "Edit FAQ" : "New FAQ"}</DialogTitle>
        <DialogDescription>New entries start as a draft — publish them when ready.</DialogDescription>
      </DialogHeader>

      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="fq-q">Question</Label>
          <Input id="fq-q" value={question} onChange={(e) => setQuestion(e.target.value)} placeholder="When is payday?" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="fq-a">Answer</Label>
          <textarea
            id="fq-a"
            rows={4}
            value={answer}
            onChange={(e) => setAnswer(e.target.value)}
            className="w-full rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring"
          />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="fq-cat">Category</Label>
            <select id="fq-cat" className={selectClass} value={category} onChange={(e) => setCategory(e.target.value)}>
              {FAQ_CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>{c.label}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="fq-tags">Tags (comma-separated)</Label>
            <Input id="fq-tags" value={tags} onChange={(e) => setTags(e.target.value)} placeholder="payday, schedule" />
          </div>
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 rounded border-input accent-brand" checked={pinned} onChange={(e) => setPinned(e.target.checked)} />
          Pin this FAQ (shows first)
        </label>
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={pending} onClick={submit}>
          {pending ? "Saving…" : isEdit ? "Save changes" : "Create draft"}
        </Button>
      </DialogFooter>
    </>
  );
}
