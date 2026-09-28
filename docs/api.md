# Mail Gazelle public API

Client integration contract for sending transactional email through Mail Gazelle. Update this file in the same change as any public API, auth, error-code, or attachment-limit change.

Operator environment names live in `.env.example`. Do not put AWS keys or raw tokens in this file.

## Base URL and auth

- Base URL: `{APP_URL}/api/v1`
- Authenticate with `Authorization: Bearer tes_…`
- Tokens are minted on a **ready** product (sending DKIM verified and MAIL FROM success). The raw token is shown once in the admin UI.
- Rate limit: 60 requests per minute per token.
- A disabled or paused team, or a product that is not `ready`, cannot send (`403`, `product_not_ready`).
- An archived product is treated as gone: its tokens return `401` `unauthenticated` (same as an invalid token).

## Onboarding

1. Create a product with a name and primary domain. Sending identity and MAIL FROM default to `notif.{primary}` and `bounce.notif.{primary}`; both can be edited on create.
2. Add the DNS records from the product checklist for that sending identity and MAIL FROM.
3. Click **Check now** (or wait for the minute poll). The product becomes `ready` only when sending DKIM and MAIL FROM are both success.
4. Create an API token.

The apex domain is not created as an SES identity on the default path. DMARC is recommended, not a ready-gate.

## Endpoints

### `POST /emails`

Persists the message, stores attachments on disk, and queues a send. Returns `202` immediately. SES is not called in the request.

```json
{
  "id": "01J…",
  "status": "queued"
}
```

JSON body:

| Field | Required | Notes |
|---|---|---|
| `to` | yes | One or more `{ "email", "name?" }`. Together with `cc` and `bcc`, at most 50 addresses |
| `cc` | no | Same shape as `to` |
| `bcc` | no | Same shape as `to`. Stored and delivered, omitted from the visible MIME headers |
| `subject` | yes | |
| `html` or `text` | one required | HTML ≤ 512 KB. Both may be sent together |
| `from` | no | Defaults to the product From. Domain must be that product's `primary` or `sending` host |
| `reply_to` | no | Defaults to the product Reply-To. Send `[]` to omit Reply-To |
| `headers` | no | Object of header name to string. `From`, `To`, `Cc`, `Bcc`, `Reply-To`, `Sender`, `Subject`, `Content-Type`, and `Return-Path` are ignored. `Message-ID` is kept when it is present |
| `tags` | no | Object of names and values, each matching `[A-Za-z0-9_-]`, name ≤ 64 characters, value ≤ 256. At most 48. Invalid tags are `validation_error` (they are not dropped). `team_id` and `product_id` are reserved |
| `idempotency_key` | no | Unique per team. Same key returns the original message and does not send again. A replay is not checked against the monthly quota |
| `attachments` | no | See below |

### `GET /emails/{id}`

Returns status, timestamps, SES message id, last event type, and attachment metadata (filename, content type, size). Does not return HTML, text, or file bytes.

### `GET /domains`

Lists the token's product domains and verification statuses.

## Attachments

Optional JSON array, same shape as Resend/Postmark:

```json
"attachments": [
  {
    "filename": "invoice.pdf",
    "content": "<base64>",
    "content_type": "application/pdf",
    "content_id": null
  }
]
```

- `filename` and `content` are required.
- `content_type` is optional (guessed from the filename).
- `content_id` is optional. When set, the part is inline (`multipart/related`). Reference it from HTML as `cid:{content_id}`. Angle brackets and a leading `cid:` are stripped. Empty or duplicate ids on one message are `attachment_invalid`.
- Filename must be a basename — path segments are rejected (`attachment_invalid`).
- The attachment count and decoded total per message are set by the team's plan. Defaults are 10 attachments and 7 MB. More attachments than allowed is `validation_error`. A larger decoded total is `attachment_too_large`, and `message` states the team's size limit. 7 MB is the platform ceiling on every plan.
- Empty or invalid base64 is `attachment_invalid`.
- A team may also have a monthly decoded-byte allowance. Crossing it is `quota_exceeded` (below), which is separate from this per-message limit.
- The assembled raw MIME (HTML, text, headers, and base64 attachments) must be 10 MB or smaller. A larger message is `message_too_large` and is not queued.

SES Simple cannot carry attachments. Mail Gazelle always sends `Content.Raw`.

## Suppressions

The send path checks the **team** (account) and global suppression lists against every `to`, `cc`, and `bcc` address. A suppressed recipient is stored as a `rejected` message (no SES send) and returns `422`:

```json
{ "message": "This recipient is suppressed.", "code": "recipient_suppressed" }
```

Permanent bounces and complaints write the address to the team list and the SES account suppression list. A suppressed reject does not consume the monthly email or attachment quota.

## Quotas

Each team's plan sets how many emails and how many decoded attachment bytes are included each UTC month, plus an optional daily send limit (UTC day). The platform admin can change these per team, so they are not a single platform-wide limit.

When a new send would pass a limit, the API returns `422` and does not queue the message. `message` says which limit was hit:

```json
{ "message": "Monthly email quota exceeded.", "code": "quota_exceeded" }
```

```json
{ "message": "Daily email limit exceeded.", "code": "quota_exceeded" }
```

```json
{ "message": "Monthly attachment quota exceeded.", "code": "quota_exceeded" }
```

Plans that allow additional sending, or additional attachment size, keep sending after the monthly amount is used. For those plans the monthly checks never return `quota_exceeded`. The daily limit applies to every plan that has one.

Each distinct `to`, `cc`, and `bcc` address on an accepted message counts as one send. The same address listed more than once counts once. Decoded attachment bytes count once per recipient. `recipient_suppressed` rejects are not counted, and their attachment bytes are not counted. Replaying an idempotency key that is already stored returns that message and does not re-check any limit. A send with no attachments is not blocked by the attachment allowance. A request that would pass the remaining monthly, daily, or attachment allowance is rejected whole.

## Errors

All API errors use:

```json
{ "message": "…", "code": "…", "details": {} }
```

`details` is optional. Responses never include AWS stack traces, message bodies, or attachment bytes.

| Code | Typical status |
|---|---|
| `unauthenticated` | 401 |
| `product_not_ready` | 403 |
| `validation_error` | 422 |
| `from_not_allowed` | 422 |
| `recipient_suppressed` | 422 |
| `attachment_invalid` | 422 |
| `attachment_too_large` | 422 |
| `quota_exceeded` | 422 |
| `html_too_large` | 422 |
| `message_too_large` | 422 |
| `rate_limited` | 429 |

## Sandbox vs production

Until AWS takes the SES account out of sandbox, you can only send to verified identities. Simulator addresses work in sandbox:

- `success@simulator.amazonses.com`
- `bounce@simulator.amazonses.com`
- `complaint@simulator.amazonses.com`

## Examples

Text send:

```bash
curl -X POST "$APP_URL/api/v1/emails" \
  -H "Authorization: Bearer tes_…" \
  -H "Content-Type: application/json" \
  -d '{
    "to": [{"email": "user@example.com"}],
    "subject": "Welcome",
    "text": "Thanks for signing up."
  }'
```

Send with a PDF:

```bash
curl -X POST "$APP_URL/api/v1/emails" \
  -H "Authorization: Bearer tes_…" \
  -H "Content-Type: application/json" \
  -d '{
    "to": [{"email": "user@example.com"}],
    "subject": "Invoice",
    "html": "<p>Your invoice is attached.</p>",
    "attachments": [{
      "filename": "invoice.pdf",
      "content": "'"$PDF_BASE64"'",
      "content_type": "application/pdf"
    }]
  }'
```
