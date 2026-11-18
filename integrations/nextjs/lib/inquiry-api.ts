// lib/inquiry-api.ts — server-only helper that forwards inquiries to the Inquiry Manager.
// The "server-only" import makes the build fail if a Client Component ever imports this file,
// so INQUIRY_API_KEY can never end up in a browser bundle.
import 'server-only';

export type FormType = 'contact' | 'quote' | 'sample' | 'other';

export interface InquiryProduct {
  name?: string;
  sku?: string;
  variant?: string;
  quantity?: number;
  unit?: string;
  notes?: string;
}

export interface InquiryPayload {
  submission_id: string;
  form_type: FormType;
  form_id?: string;
  form_name?: string;
  submitted_at?: string;
  locale?: string;
  full_name?: string;
  company?: string;
  email?: string;
  phone?: string;
  country?: string;
  address?: string;
  city?: string;
  postcode?: string;
  message?: string;
  products?: InquiryProduct[];
  fields?: Record<string, string | number | boolean | null | Array<string | number | boolean> | { label: string; value: unknown }>;
  source?: { page_url?: string; ip?: string };
  test?: boolean;
}

export type ForwardResult =
  | { ok: true; status: number; inquiryId: number; replayed: boolean }
  | { ok: false; status: number; retryable: boolean; code: string; message: string; fields?: Record<string, string> };

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * POST one inquiry. Network errors, timeouts, 429 and 5xx are retried with backoff,
 * always with the same submission_id, so the Inquiry Manager stores the inquiry once.
 */
export async function forwardInquiry(
  payload: InquiryPayload,
  options: { attempts?: number; timeoutMs?: number } = {},
): Promise<ForwardResult> {
  const url = process.env.INQUIRY_API_URL;
  const key = process.env.INQUIRY_API_KEY;
  if (!url || !key) {
    throw new Error('Set INQUIRY_API_URL and INQUIRY_API_KEY as server environment variables (never NEXT_PUBLIC_).');
  }
  const attempts = options.attempts ?? 3;
  const timeoutMs = options.timeoutMs ?? 8000;
  const body = JSON.stringify(payload);
  let last: ForwardResult = { ok: false, status: 0, retryable: true, code: 'not_sent', message: 'Not sent' };

  for (let attempt = 1; attempt <= attempts; attempt++) {
    let retryAfterMs = Math.min(8000, 1000 * 2 ** (attempt - 1));
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${key}`,
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Idempotency-Key': payload.submission_id,
        },
        body,
        cache: 'no-store',
        redirect: 'manual',
        signal: AbortSignal.timeout(timeoutMs),
      });
      const data: any = await res.json().catch(() => null);
      if (res.status === 200 || res.status === 201) {
        return { ok: true, status: res.status, inquiryId: Number(data?.inquiry_id), replayed: Boolean(data?.replayed) };
      }
      const retryable = res.status === 429 || res.status >= 500;
      last = {
        ok: false,
        status: res.status,
        retryable,
        code: String(data?.error?.code ?? `http_${res.status}`),
        message: String(data?.error?.message ?? `HTTP ${res.status}`),
        fields: data?.error?.fields,
      };
      if (!retryable) {
        return last; // 4xx: fix the data or the key; retrying would not help.
      }
      const retryAfter = Number(res.headers.get('retry-after'));
      if (Number.isFinite(retryAfter) && retryAfter > 0) {
        retryAfterMs = Math.min(10000, retryAfter * 1000);
      }
    } catch (error) {
      last = { ok: false, status: 0, retryable: true, code: 'network_error', message: (error as Error).message };
    }
    if (attempt < attempts) {
      await sleep(retryAfterMs + Math.floor(Math.random() * 250));
    }
  }
  return last;
}
