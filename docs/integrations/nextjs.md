# Next.js (App Router)

The browser posts the form to your own route handler (`/api/inquiry`), which runs on the server and forwards it with the key. The key is a server-only environment variable and never reaches the client bundle.

## 1. Where the code goes

| File | Location in the Next.js project |
|---|---|
| `lib/inquiry-api.ts` | `lib/` (or `src/lib/`) |
| `app/api/inquiry/route.ts` | `app/api/inquiry/route.ts` (or under `src/app/`) |
| `components/InquiryForm.tsx` | any components folder |

Install the guard package once: `npm install server-only`.

## 2. Store the key

`.env.local` for development; in production set the same variables in the hosting dashboard (Vercel: Project → Settings → Environment Variables).

```
INQUIRY_API_URL={{ENDPOINT}}
INQUIRY_API_KEY=aiq_…
```

Never use a `NEXT_PUBLIC_` prefix: those values are embedded in browser JavaScript. `import 'server-only'` in `lib/inquiry-api.ts` makes the build fail if a Client Component imports it.

## 3. Server helper

<!-- include: integrations/nextjs/lib/inquiry-api.ts lang=ts -->

## 4. Route handler and field mapping

`clientSubmissionId` is created once per form view in the browser. The handler turns it into `submission_id`, so a retry or double click is stored once. The payload deliberately has no `submitted_at`, so a resubmission is byte-identical; the Inquiry Manager records the receipt time itself.

<!-- include: integrations/nextjs/app/api/inquiry/route.ts lang=ts -->

<!-- include: integrations/nextjs/components/InquiryForm.tsx lang=tsx -->

## 5. Validation failures and retries

- The helper retries network errors, timeouts, `429` (honouring `Retry-After`) and `5xx` up to 3 times with the same ID.
- `422` is returned to the form with field messages; the form then uses a new token for the corrected submission.
- If all retries fail, the route answers `503` and the visitor can press Send again (same token, no duplicate). Serverless platforms have no durable local disk; if you need guaranteed delivery during long outages, write failed payloads to your database or a queue and replay them with the same `submission_id`.

## 6. Send a test inquiry and confirm receipt

With `npm run dev` running:

```
curl -X POST http://localhost:3000/api/inquiry -H "Content-Type: application/json" \
  -d '{"clientSubmissionId":"3f1d2c4b-5a6e-4f70-8a9b-0c1d2e3f4a5b","name":"Next test","email":"test@example.com","message":"Connection test"}'
```

Expect `{"ok":true,"inquiryId":…}`; running it again returns the same `inquiryId`. The inquiry appears in the inbox under **{{WEBSITE_NAME}}**.
