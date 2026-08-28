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
  method?: "GET" | "POST" | "PATCH" | "DELETE";
  body?: unknown;
  /** Cache tags so a mutation can revalidate exactly what it invalidated. */
  tags?: string[];
  revalidate?: number | false;
  /** Skip the session cookie — only for login itself. */
  anonymous?: boolean;
};

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
    throw new ApiRequestError(
      response.status,
      payload?.error ?? {
        code: "network.unreachable",
        message: "The API could not be reached.",
        request_id: "",
      },
    );
  }

  return payload;
}
