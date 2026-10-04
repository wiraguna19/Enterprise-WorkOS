"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useT } from "@/i18n/I18nProvider";
import { archiveKpi } from "./actions";

/** Archiving, in two steps. The history is kept; the KPI leaves the list. */
export function ArchiveKpi({ id, after = "/kpis" }: { id: string; after?: string }) {
  const t = useT();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  if (!confirming) {
    return (
      <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
        {t("kpi.archive")}
      </Button>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-body-sm text-n-700">{t("kpi.archiveConfirm")}</span>
      <Button
        variant="danger"
        size="sm"
        disabled={pending}
        onClick={() =>
          startTransition(async () => {
            const result = await archiveKpi(id);

            setError(result.error);

            if (result.error === null) {
              router.push(after);
              router.refresh();
            }
          })
        }
      >
        {t("kpi.archiveYes")}
      </Button>
      <Button size="sm" disabled={pending} onClick={() => setConfirming(false)}>
        {t("kpi.keep")}
      </Button>
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </div>
  );
}
