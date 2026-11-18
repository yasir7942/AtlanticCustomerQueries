// app/api/inquiry/route.ts — Next.js App Router route handler (runs on the server).
// The browser posts the form here; this handler forwards it server-to-server.
import { forwardInquiry, type FormType, type InquiryPayload } from '../../../lib/inquiry-api';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const TYPES: FormType[] = ['contact', 'quote', 'sample'];

const text = (v: unknown, max: number): string => (typeof v === 'string' ? v.trim().slice(0, max) : '');
// Omit empty optional values instead of sending "".
const compact = <T extends object>(o: T): T =>
  Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== undefined)) as T;

export async function POST(request: Request): Promise<Response> {
  let input: any;
  try {
    input = await request.json();
  } catch {
    return Response.json({ ok: false, message: 'Invalid request.' }, { status: 400 });
  }
  // The form creates this UUID once when it is shown; resubmitting the same form reuses it.
  const clientId = text(input?.clientSubmissionId, 64);
  if (!UUID.test(clientId)) {
    return Response.json({ ok: false, message: 'Missing form token. Reload the page.' }, { status: 400 });
  }
  if (text(input?.website, 200) !== '') {
    return Response.json({ ok: true }); // honeypot filled: pretend success, send nothing
  }
  const formType: FormType = TYPES.includes(input?.formType) ? input.formType : 'contact';

  const payload: InquiryPayload = compact({
    submission_id: `next-${clientId}`,
    form_type: formType,
    form_id: `nextjs-${formType}`,
    // No submitted_at: a browser resubmission rebuilds this payload, and it must be byte-identical
    // to be recognised as the same inquiry (the API records receipt time itself).
    locale: text(input?.locale, 16) || 'en',
    full_name: text(input?.name, 190),
    company: text(input?.company, 190),
    email: text(input?.email, 190),
    phone: text(input?.phone, 50), // keep as text
    country: text(input?.country, 100),
    message: text(input?.message, 20000),
    source: {
      page_url: text(request.headers.get('referer'), 2048) || undefined,
      ip: (request.headers.get('x-forwarded-for') ?? '').split(',')[0].trim() || undefined,
    },
  } as InquiryPayload);
  if (Array.isArray(input?.products)) {
    payload.products = input.products
      .slice(0, 50)
      .map((p: any) => ({ name: text(p?.name, 200), quantity: Number(p?.quantity) || undefined }))
      .filter((p: { name: string }) => p.name !== '');
  }
  if (input?.neededBy) {
    payload.fields = { needed_by: { label: 'Needed by', value: text(input.neededBy, 40) } };
  }
  if (payload.source && !payload.source.page_url?.startsWith('http')) delete payload.source.page_url;
  if (payload.source && payload.source.ip && !/^[0-9a-f:.]+$/i.test(payload.source.ip)) delete payload.source.ip;

  const result = await forwardInquiry(payload);
  if (result.ok) {
    return Response.json({ ok: true, inquiryId: result.inquiryId });
  }
  if (result.status === 422) {
    return Response.json({ ok: false, message: 'Please check the highlighted fields.', fields: result.fields ?? {} }, { status: 422 });
  }
  if (result.status === 409) {
    // Same form token, different content (the visitor edited and resent): the client starts a new token.
    return Response.json({ ok: false, conflict: true, message: 'Please send the form again.' }, { status: 409 });
  }
  console.error('Inquiry forwarding failed', result.status, result.code); // no personal data in logs
  // Retryable failure: the browser may retry with the same clientSubmissionId (no duplicates).
  return Response.json({ ok: false, retry: result.retryable, message: 'We could not send your inquiry. Please try again.' }, { status: 503 });
}
