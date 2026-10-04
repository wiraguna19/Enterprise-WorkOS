"use client";

import { useEffect } from "react";
import { markAnnouncementsRead } from "./actions";

/**
 * Marks what is on screen as read, once it has been shown.
 *
 * After render rather than on the server's GET: reading a list is not a
 * write, and a prefetch of the page would otherwise mark everything read
 * before anybody looked at it.
 */
export function MarkRead({ ids }: { ids: string[] }) {
  const key = ids.join(",");

  useEffect(() => {
    if (key === "") return;

    void markAnnouncementsRead(key.split(","));
  }, [key]);

  return null;
}
