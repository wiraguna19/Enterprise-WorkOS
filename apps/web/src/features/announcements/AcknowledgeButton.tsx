"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useT } from "@/i18n/I18nProvider";
import { acknowledgeAnnouncement } from "./actions";

/**
 * "I have read this." Asked for by the publisher on what matters — a policy,
 * a schedule — and a deliberate act, which opening the list is not (ADR 0061).
 */
export function AcknowledgeButton({ id }: { id: string }) {
  const t = useT();
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button
        variant="affirmative"
        size="sm"
        disabled={pending}
        onClick={() =>
          startTransition(async () => {
            const result = await acknowledgeAnnouncement(id);

            setError(result.error);
            if (result.error === null) router.refresh();
          })
        }
      >
        {pending ? t("ann.acknowledging") : t("ann.acknowledge")}
      </Button>
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </div>
  );
}
