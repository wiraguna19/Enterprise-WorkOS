import { getSessionToken } from "./session";

/**
 * Server-side API client.
 *
 * Every response is the envelope defined in docs/05 §3, so error handling is
 * uniform: callers branch on `error.code` (stable, machine-readable), never on
 * `error.message` (localised, and will change).
 */

const BASE_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export type ApiError = {
  code: string;
  message: string;
  request_id: string;
  details?: Record<string, unknown>;
};

export class ApiRequestError extends Error {
  constructor(
    readonly status: number,
    readonly error: ApiError,
  ) {
    super(error.message);
    this.name = "ApiRequestError";
  }
}

type RequestOptions = {
  // PUT was missing until the notification preferences screen was wired: the
  // one PUT route in the API was, literally, uncallable from this client. A
  // hand-written contract drifts in both directions — a field the API stopped
  // sending, and a verb the client never learned.
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Cache tags so a mutation can revalidate exactly what it invalidated. */
  tags?: string[];
  revalidate?: number | false;
  /** Skip the session cookie — only for login itself. */
  anonymous?: boolean;
};

/**
 * An API error as a sentence somebody can act on.
 *
 * `error.details` carries two completely different things, and treating them
 * alike is how a screen ends up printing `already_organization_wide manager` at
 * a person. For `validation.failed` it is field → MESSAGES, and those messages
 * are the useful part: the validator names the field it refused and why. For a
 * domain refusal it is structured FACTS — a refusal code, a count, an id — for
 * a client to branch on, and the human sentence is `message`.
 *
 * Found by granting a role twice in the product. Both the rule builder and the
 * graph editor claimed in their own comments to "print the refusal verbatim",
 * and both were printing the metadata instead.
 */
/**
 * `code` comes back as well as the sentence (ADR 0034).
 *
 * Callers branch on the code and never on the message (docs/05 §3), and until
 * now this helper threw the code away — which was fine while every refusal
 * meant the same thing to the interface. "Confirm your password" does not: it
 * is the one refusal a screen answers with a form rather than with red text.
 */
/**
 * The code the API answers with when an act needs the password again
 * (ADR 0034).
 *
 * Here rather than beside the Server Action that uses it: a `"use server"`
 * module may only export async functions, so a constant in one is a build
 * error — and the codes this app branches on belong with the client that
 * receives them anyway.
 */
export const REAUTH_CODE = "auth.reauthentication_required";

export function describeApiError(
  error: unknown,
): { error: string; code?: string; requestId?: string } {
  if (!(error instanceof ApiRequestError)) {
    return { error: "We could not reach the server. Please try again." };
  }

  const requestId = error.error.request_id;

  if (error.error.code === "validation.failed") {
    const fields = (error.error.details ?? {}) as Record<string, string[]>;
    const messages = Object.values(fields).flat();

    if (messages.length > 0) {
      return { error: messages.join(" "), code: error.error.code, requestId };
    }
  }

  return { error: error.error.message, code: error.error.code, requestId };
}

export async function api<T>(
  path: string,
  options: RequestOptions = {},
): Promise<{ data: T; meta?: Record<string, unknown> }> {
  const { method = "GET", body, tags, revalidate, anonymous } = options;

  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  if (body !== undefined) headers["Content-Type"] = "application/json";

  if (!anonymous) {
    const token = await getSessionToken();
    if (token) headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    next: tags || revalidate !== undefined ? { tags, revalidate } : undefined,
    // Writes are never cached, and a stale list after a write is a bug report.
    cache: method === "GET" ? undefined : "no-store",
  });

  if (response.status === 204) {
    return { data: undefined as T };
  }

  const payload = await response.json().catch(() => null);

  if (!response.ok) {
    // A response WITHOUT this API's error envelope. It used to be reported as
    // "The API could not be reached", which was true of exactly one case and
    // wrong about the common one: a 500 means the API was reached, answered,
    // and broke. That sentence sent somebody looking for a dropped connection
    // while the server log held the actual error — which is what happened the
    // first time this page met a column the dev database did not have yet.
    throw new ApiRequestError(
      response.status,
      payload?.error ?? {
        code: response.status >= 500 ? "server.error" : "api.unexpected_response",
        message:
          response.status >= 500
            ? `The server failed to handle that (HTTP ${response.status}). The API log has the reason.`
            : `The API answered ${response.status} with nothing this app understands.`,
        request_id: response.headers.get("x-request-id") ?? "",
      },
    );
  }

  return payload;
}
