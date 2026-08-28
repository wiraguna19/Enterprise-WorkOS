"use client";

import { Button } from "@/components/ui/Button";
import { ErrorState } from "@/components/ui/ErrorState";

/**
 * Route-level boundary. A crash in one page must not take down the shell
 * (docs/07 §7).
 */
export default function AppError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <ErrorState
      message="This page could not be loaded. The problem has been logged."
      requestId={error.digest}
      retry={<Button onClick={reset}>Try again</Button>}
    />
  );
}
