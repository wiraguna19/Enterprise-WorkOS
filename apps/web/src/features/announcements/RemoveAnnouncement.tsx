"use client";

import { useRouter } from "next/navigation";
import { useState, useTransition } from "react";
import { Button } from "@/components/ui/Button";
import { useT } from "@/i18n/I18nProvider";
import { removeAnnouncement } from "./actions";

/**
 * Retracting an announcement. Two steps rather than a browser dialog: the
 * first click says what will happen, the second does it.
 */
export function RemoveAnnouncement({ id }: { id: string }) {
  const t = useT();
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  if (!confirming) {
    return (
      <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
        {t("ann.remove")}
      </Button>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-body-sm text-n-700">{t("ann.removeConfirm")}</span>
      <Button
        variant="danger"
        size="sm"
        disabled={pending}
        onClick={() =>
          startTransition(async () => {
            const result = await removeAnnouncement(id);

            setError(result.error);

            if (result.error === null) {
              router.push("/announcements");
              router.refresh();
            }
          })
        }
      >
        {t("ann.removeYes")}
      </Button>
      <Button size="sm" disabled={pending} onClick={() => setConfirming(false)}>
        {t("ann.keep")}
      </Button>
      {error && (
        <span role="alert" className="text-caption text-s-danger">
          {error}
        </span>
      )}
    </div>
  );
}
