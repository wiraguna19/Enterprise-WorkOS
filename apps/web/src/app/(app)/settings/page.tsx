import Link from "next/link";
import { PageHeader } from "@/components/ui/PageHeader";
import { requireUser } from "@/lib/auth";

/**
 * The settings index (docs/08 §7).
 *
 * It did not exist while there was one settings screen, and the nav pointed
 * straight at it — an index of one is a page that adds a click and says
 * nothing. Two more screens arrived with the workflow catalogue, so it exists
 * now, which is the only reason it should.
 *
 * Each entry is gated on the permission its own route requires, so nothing here
 * leads to a 403: a nav entry whose target refuses reads as a broken product
 * rather than an unbuilt one.
 */
const SECTIONS: Array<{
  href: string;
  label: string;
  description: string;
  permission?: string;
}> = [
  {
    href: "/settings/notifications",
    label: "Notifications",
    description: "Which interruptions reach you, and where.",
  },
  {
    href: "/settings/roles",
    label: "Roles",
    description: "What each role may do, and the ones you write yourself.",
    permission: "role.view",
  },
  {
    href: "/settings/audit",
    label: "Audit log",
    description: "Who did what, and when — sign-ins, invitations, role changes.",
    permission: "audit_log.view",
  },
  {
    href: "/settings/workflows",
    label: "Workflows",
    description: "The statuses work moves through, and which moves are legal.",
    permission: "workflow.view",
  },
  {
    href: "/settings/rules",
    label: "Automation rules",
    description: "What the system does on its own — and what it has actually done.",
    permission: "workflow.view",
  },
];

export default async function SettingsPage() {
  const me = await requireUser();

  const sections = SECTIONS.filter(
    (section) => !section.permission || me.permissions.includes(section.permission),
  );

  return (
    <div className="space-y-5">
      <PageHeader title="Settings" description={`${sections.length} areas`} />

      <ul className="divide-y divide-n-100 border-y border-n-100">
        {sections.map((section) => {
          // "/settings/rules" → "settings-rules". The leading slash would make
          // an id that starts with a dash — legal HTML, and the kind of thing
          // that breaks the first tool that treats an id as a CSS selector.
          const id = section.href.replace(/^\//, "").replace(/\//g, "-");

          return (
            <li key={section.href}>
              {/* The whole row is the target, and the link's NAME is the label
                  alone. A link whose accessible name is its entire contents is
                  announced as "Automation rules What the system does on its own
                  — and what it has actually done." — one long sentence where a
                  name should be, and a description that never gets to be one.
                  `aria-labelledby` and `aria-describedby` split them, which is
                  also what lets a test address the entry by its name. */}
              <Link
                href={section.href}
                aria-labelledby={`${id}-label`}
                aria-describedby={`${id}-description`}
                className="flex flex-col gap-0.5 py-3 transition-colors duration-[120ms] ease-standard hover:bg-n-50"
              >
                <span id={`${id}-label`} className="font-medium text-n-900">
                  {section.label}
                </span>
                <span id={`${id}-description`} className="max-w-prose text-caption text-n-500">
                  {section.description}
                </span>
              </Link>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
