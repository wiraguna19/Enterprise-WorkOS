import { avatarTone, initials } from "@/lib/format";
import { clsx } from "@/lib/clsx";

const SIZES = { sm: "size-5 text-[10px]", md: "size-6 text-micro", lg: "size-8 text-caption" };

export function Avatar({
  id,
  name,
  size = "md",
}: {
  id: string;
  name: string;
  size?: keyof typeof SIZES;
}) {
  return (
    <span
      className={clsx(
        "inline-flex shrink-0 items-center justify-center rounded-full font-semibold",
        avatarTone(id),
        SIZES[size],
      )}
      title={name}
    >
      {initials(name)}
    </span>
  );
}

/** Stacks overlap and collapse past four — a row of twelve avatars is noise. */
export function AvatarStack({
  people,
  max = 4,
}: {
  people: Array<{ id: string; name: string }>;
  max?: number;
}) {
  const shown = people.slice(0, max);
  const overflow = people.length - shown.length;

  return (
    <span className="flex items-center">
      {shown.map((person, index) => (
        <span key={person.id} className={index > 0 ? "-ml-1.5" : ""}>
          <Avatar id={person.id} name={person.name} />
        </span>
      ))}
      {overflow > 0 && (
        <span className="-ml-1.5 inline-flex size-6 items-center justify-center rounded-full bg-n-100 text-micro font-semibold text-n-500">
          +{overflow}
        </span>
      )}
    </span>
  );
}
