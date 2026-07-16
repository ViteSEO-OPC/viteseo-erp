export type MessageAuthor = "ai" | "user";

export type RichTextSegment =
  | { type: "text"; text: string }
  | { type: "bold"; text: string }
  | { type: "emphasis"; text: string }
  // Inline navigation link — rendered as a clickable label that routes via the action engine.
  | { type: "link"; label: string; route: string };

export type Message = {
  id: string;
  author: MessageAuthor;
  segments: RichTextSegment[];
  timestamp: string;
};

export type SuggestedPrompt = {
  id: string;
  label: string;
};

export type CopilotStatus = {
  active: boolean;
  leadsAnalyzed: number;
};
