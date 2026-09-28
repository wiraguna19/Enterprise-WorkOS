/**
 * This product's SAML metadata, at the URL that is also its entity id
 * (ADR 0052).
 *
 * An identity provider's console imports it from here — this origin, because
 * the assertion consumer it names is on this origin. The API writes the
 * document; this route only passes it through, unread, so there is one place
 * that decides what it says.
 */
const BASE_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";

export async function GET() {
  const response = await fetch(`${BASE_URL}/auth/sso/metadata`, {
    method: "GET",
    headers: { Accept: "application/samlmetadata+xml" },
    cache: "no-store",
  });

  return new Response(await response.text(), {
    status: response.status,
    headers: {
      "Content-Type": response.headers.get("content-type") ?? "application/samlmetadata+xml",
    },
  });
}
