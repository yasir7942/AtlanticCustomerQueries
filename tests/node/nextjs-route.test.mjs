// Exercises integrations/nextjs/app/api/inquiry/route.ts against a running Inquiry Manager.
// Env: INQUIRY_API_URL, INQUIRY_API_KEY. Prints a JSON summary.
import { randomUUID } from 'node:crypto';
const { POST } = await import('../../integrations/nextjs/app/api/inquiry/route.ts');

const call = async (body, headers = {}) => {
  const res = await POST(new Request('http://site.test/api/inquiry', {
    method: 'POST',
    headers: { 'content-type': 'application/json', referer: 'https://site.test/contact', 'x-forwarded-for': '198.51.100.23', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  }));
  return { status: res.status, json: await res.json() };
};

const id = randomUUID();
const form = {
  clientSubmissionId: id, formType: 'quote', name: 'Next Visitor', email: 'next@example.test', phone: '+44 20 0000 0000',
  message: 'Quote via Next.js route handler', products: [{ name: 'Pump P-10', quantity: 3 }], neededBy: '2026-12-15',
};
const first = await call(form);
const retry = await call(form);
const invalid = await call({ ...form, clientSubmissionId: randomUUID(), email: 'not-an-email' });
const noToken = await call({ ...form, clientSubmissionId: 'abc' });
const honeypot = await call({ ...form, clientSubmissionId: randomUUID(), website: 'http://spam' });
const badJson = await call('{oops');
console.log(JSON.stringify({ first, retry, invalid, noToken, honeypot, badJson, submissionId: `next-${id}` }));
