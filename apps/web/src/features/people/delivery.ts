/** A person's delivery without a KPI (ADR 0062, "Delivery without a KPI"). */
export type DeliveryFigures = { finished: number; dated: number; on_time: number; on_time_rate: number | null };

export type Delivery = {
  summary: DeliveryFigures & { from: string; to: string };
  /** Oldest first, ending with the current week. */
  weeks: Array<DeliveryFigures & { period_start: string; partial: boolean }>;
};

export type DeliveredItem = {
  work_item_id: string;
  reference: string;
  title: string;
  completed_at: string;
  due_at: string | null;
  hours: number | null;
  /** Null when the item had no due date: neither late nor on time. */
  late: boolean | null;
};
