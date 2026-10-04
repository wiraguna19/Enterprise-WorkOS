"use client";

import { useEffect } from "react";
import { TEXT_SIZE_COOKIE, type TextSize } from "./text-size";

/**
 * Brings this browser in line with the account's reading size.
 *
 * The root layout renders `<html data-text-size>` from the cookie, which is
 * right on every device the size was chosen on. On another device the account
 * says one thing and the cookie another, so the account wins here and the
 * cookie is corrected for the next first paint.
 */
export function TextSizeSync({ size }: { size: TextSize }) {
  useEffect(() => {
    document.documentElement.dataset.textSize = size;
    document.cookie = `${TEXT_SIZE_COOKIE}=${size}; path=/; max-age=31536000; samesite=lax`;
  }, [size]);

  return null;
}
