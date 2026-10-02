import { DEFAULT_LOCALE, type Locale } from "./config";
import { en, type MessageKey, type Messages } from "./messages/en";
import { id } from "./messages/id";

/**
 * `t("key", { name })` over typed dictionaries (ADR 0060).
 *
 * No ICU syntax. Interpolation is `{name}`, and a plural is a key pair
 * `x.one` / `x.other` chosen by `Intl.PluralRules`. That covers every string
 * the product has; the day it does not is the day to adopt a library, and the
 * dictionaries carry over unchanged.
 */
const DICTIONARIES: Record<Locale, Messages> = { en, id };

type Vars = Record<string, string | number>;

/** `access.entries` for the pair `access.entries.one` / `access.entries.other`. */
export type PluralKey = {
  [K in MessageKey]: K extends `${infer Base}.other` ? Base : never;
}[MessageKey];

export type Translator = {
  (key: MessageKey, vars?: Vars): string;
  /** The form for `count`; `{count}` is filled in with it. */
  plural: (key: PluralKey, count: number, vars?: Vars) => string;
  locale: Locale;
};

function fill(message: string, vars?: Vars): string {
  if (vars === undefined) return message;

  return message.replace(/\{(\w+)\}/g, (whole, name: string) =>
    name in vars ? String(vars[name]) : whole,
  );
}

export function translator(locale: Locale = DEFAULT_LOCALE): Translator {
  const messages = DICTIONARIES[locale];
  const rules = new Intl.PluralRules(locale);

  const t = ((key: MessageKey, vars?: Vars) => fill(messages[key], vars)) as Translator;

  t.plural = (key, count, vars) => {
    const form = rules.select(count) === "one" ? "one" : "other";

    return fill(messages[`${key}.${form}` as MessageKey], { count, ...vars });
  };
  t.locale = locale;

  return t;
}
