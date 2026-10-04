/** One group an announcement can be addressed to (ADR 0061). */
export type AudienceType = "organization" | "department" | "team";

export type Audience = { type: AudienceType; id: string | null; name: string | null };

export type Announcement = {
  id: string;
  title: string;
  /** Plain text with line breaks. Rendered as text, never as HTML. */
  body: string;
  pinned: boolean;
  requires_acknowledgement: boolean;
  audience: Audience;
  author: { membership_id: string; name: string | null };
  published_at: string;
  expires_at: string | null;
  expired: boolean;
  read: boolean;
  acknowledged: boolean;
  can_manage: boolean;
  /** Only for whoever may manage it. */
  stats?: { audience: number; read: number; acknowledged: number };
  /** Only for whoever may manage it, and only when acknowledgement was asked for. */
  not_acknowledged?: Array<{ membership_id: string; name: string | null }>;
};
