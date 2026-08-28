/**
 * Says what failed, offers retry, and carries the request ID — which is the
 * only thing that makes a support conversation tractable (docs/07 §7).
 */
export function ErrorState({
  message,
  requestId,
  retry,
}: {
  message: string;
  requestId?: string;
  retry?: React.ReactNode;
}) {
  return (
    <div className="border border-n-200 px-6 py-8 rounded-md">
      <h3 className="text-h2 font-semibold text-n-900">Something went wrong</h3>
      <p className="mt-1 max-w-prose text-body text-n-500">{message}</p>
      {retry && <div className="mt-3">{retry}</div>}
      {requestId && (
        <p className="mt-4 font-mono text-caption text-n-500">Request {requestId}</p>
      )}
    </div>
  );
}
