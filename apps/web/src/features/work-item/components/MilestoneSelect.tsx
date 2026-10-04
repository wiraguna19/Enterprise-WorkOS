import { INPUT } from "@/components/ui/Field";
import { useT } from "@/i18n/I18nProvider";
import type { MilestoneOption } from "../actions";

/**
 * Which of the project's milestones this work belongs to (ADR 0056).
 *
 * The date is in the option because two milestones are most often told apart
 * by when they are, not by what they are called ("Beta" and "Beta 2"). A
 * finished or missed milestone is still offered — work is filed under a
 * shipped release after the fact all the time — but says so, so it is not
 * picked by accident.
 */
export function MilestoneSelect({
  id,
  value,
  onChange,
  milestones,
  disabled = false,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  milestones: MilestoneOption[];
  disabled?: boolean;
}) {
  // The item's own milestone is kept even when the list did not include it,
  // so an edit never silently moves a field nobody touched.
  const t = useT();
  const known = value === "" || milestones.some((milestone) => milestone.id === value);

  return (
    <select
      id={id}
      value={value}
      disabled={disabled}
      onChange={(event) => onChange(event.target.value)}
      className={INPUT}
    >
      <option value="">{t("form.none")}</option>
      {!known && <option value={value}>{t("msel.current")}</option>}
      {milestones.map((milestone) => (
        <option key={milestone.id} value={milestone.id}>
          {milestone.name}
          {milestone.due_date ? ` · ${milestone.due_date}` : ""}
          {milestone.status === "completed" ? t("msel.completed") : milestone.status === "missed" ? t("msel.missed") : ""}
        </option>
      ))}
    </select>
  );
}
