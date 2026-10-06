import Link from "next/link";
import { PageBody } from "@/components/ui/PageBody";
import { PageHeader } from "@/components/ui/PageHeader";
import { Panel } from "@/components/ui/Panel";
import { asLocale } from "@/i18n/config";
import { translator } from "@/i18n/translate";
import { requireUser } from "@/lib/auth";

/**
 * The words live in the dictionaries (ADR 0060); `key` names the pair
 * `settings.<key>.label` / `settings.<key>.description`.
 */
type SectionKey =
  | "organization"
  | "leave"
  | "notifications"
  | "language"
  | "display"
  | "twoFactor"
  | "sessions"
  | "apiTokens"
  | "serviceAccounts"
  | "sso"
  | "roles"
  | "fields"
  | "templates"
  | "webhooks"
  | "audit"
  | "workflows"
  | "rules";

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
  key: SectionKey;
  permission?: string;
}> = [
  { href: "/settings/organization", key: "organization", permission: "organization.view" },
  { href: "/settings/leave", key: "leave", permission: "leave.manage" },
  { href: "/settings/notifications", key: "notifications" },
  { href: "/settings/language", key: "language" },
  { href: "/settings/display", key: "display" },
  { href: "/settings/two-factor", key: "twoFactor" },
  { href: "/settings/sessions", key: "sessions" },
  { href: "/settings/api-tokens", key: "apiTokens" },
  { href: "/settings/service-accounts", key: "serviceAccounts", permission: "service_account.manage" },
  { href: "/settings/sso", key: "sso", permission: "sso.manage" },
  { href: "/settings/roles", key: "roles", permission: "role.view" },
  { href: "/settings/fields", key: "fields", permission: "custom_field.manage" },
  { href: "/settings/templates", key: "templates", permission: "work_item_template.manage" },
  { href: "/settings/webhooks", key: "webhooks", permission: "webhook.manage" },
  { href: "/settings/audit", key: "audit", permission: "audit_log.view" },
  { href: "/settings/workflows", key: "workflows", permission: "workflow.view" },
  { href: "/settings/rules", key: "rules", permission: "workflow.view" },
];

export default async function SettingsPage() {
  const me = await requireUser();
  const t = translator(asLocale(me.user.locale));

  const sections = SECTIONS.filter(
    (section) => !section.permission || me.permissions.includes(section.permission),
  );

  return (
    <div className="space-y-5">
      <PageHeader title={t("settings.title")} description={t.plural("settings.areas", sections.length)} />

      <PageBody>
        <Panel id="areas" title={t("settings.panel.title")} description={t("settings.panel.description")} bleed>
          <ul className="divide-y divide-n-100">
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
                className="flex flex-col gap-0.5 px-4 py-3 transition-colors duration-[120ms] ease-standard hover:bg-n-50"
              >
                <span id={`${id}-label`} className="font-medium text-n-900">
                  {t(`settings.${section.key}.label`)}
                </span>
                <span id={`${id}-description`} className="max-w-prose text-caption text-n-500">
                  {t(`settings.${section.key}.description`)}
                </span>
              </Link>
            </li>
          );
        })}
          </ul>
        </Panel>
      </PageBody>
    </div>
  );
}
