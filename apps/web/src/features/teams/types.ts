/** Mirrors TeamResource (docs/05 §3). */

export type TeamMember = {
  membership_id: string;
  name: string | null;
  job_title: string | null;
  role: "member" | "lead";
  joined_at: string | null;
};

export type Team = {
  id: string;
  type: "team";
  name: string;
  key: string;
  description: string;
  department?: { id: string | null; name: string | null };
  lead_membership_id: string | null;
  member_count?: number;
  members?: TeamMember[];
  archived: boolean;
  permissions: Record<string, boolean>;
};
