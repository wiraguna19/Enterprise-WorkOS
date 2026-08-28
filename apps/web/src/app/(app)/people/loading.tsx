/**
 * A skeleton that matches the final layout, so there is no shift when the data
 * lands. A spinner in the middle of the page is a last resort (docs/07 §7).
 */
export default function Loading() {
  return (
    <div className="space-y-6">
      <div className="border-b border-n-100 pb-4">
        <div className="h-8 w-32 animate-pulse rounded-sm bg-n-100" />
        <div className="mt-2 h-4 w-48 animate-pulse rounded-sm bg-n-50" />
      </div>
      <div className="space-y-px">
        {Array.from({ length: 8 }).map((_, index) => (
          <div key={index} className="flex h-11 items-center gap-2 border-b border-n-100 px-3">
            <div className="size-6 animate-pulse rounded-full bg-n-100" />
            <div className="h-3 w-40 animate-pulse rounded-sm bg-n-100" />
            <div className="ml-auto h-3 w-24 animate-pulse rounded-sm bg-n-50" />
          </div>
        ))}
      </div>
    </div>
  );
}
