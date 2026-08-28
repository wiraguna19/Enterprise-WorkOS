/**
 * Mirrors PersonResource (docs/05 §3).
 *
 * Two types for one endpoint, because the API sends two shapes: the directory
 * sends what you compare people BY, and the profile adds what you look one
 * person UP for. Modelling the extra fields as optional on a single type would
 * let a directory row read `person.manager` and silently render nothing.
 */

export type Person = {
  id: string;
  name: string;
  email: string;
  status: string;
  joined_at: string | null;
  job_title: string | null;
  employment_type: string | null;
  weekly_capacity_hours: string | null;
  department: { id: string; name: string } | null;
  permissions: Record<string, boolean>;
};

/** A colleague as referenced from someone else's reporting line. */
export type PersonRef = {
  id: string;
  name: string | null;
  job_title: string | null;
};

export type PersonDetail = Person & {
  roles: Array<{ id: string; key: string; name: string }>;
  manager: PersonRef | null;
  direct_reports: PersonRef[];
  work_location: string | null;
  hired_at: string | null;
  /** Only sent to someone who can edit the record; absent for everyone else. */
  employee_number?: string | null;
};
