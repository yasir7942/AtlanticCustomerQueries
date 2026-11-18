'use client';
// components/InquiryForm.tsx — browser form. It only talks to /api/inquiry on the same site;
// it never sees the Inquiry Manager URL or key.
import { useState, type FormEvent } from 'react';

type State = { kind: 'idle' } | { kind: 'sending' } | { kind: 'sent' } | { kind: 'error'; message: string; fields?: Record<string, string> };

export default function InquiryForm({ formType = 'contact' }: { formType?: 'contact' | 'quote' | 'sample' }) {
  // One ID per form view: retries and double clicks reuse it, so only one inquiry is stored.
  const [clientSubmissionId, setClientSubmissionId] = useState(() => crypto.randomUUID());
  const [state, setState] = useState<State>({ kind: 'idle' });

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (state.kind === 'sending') return;
    setState({ kind: 'sending' });
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    const send = (id: string) =>
      fetch('/api/inquiry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...data, formType, clientSubmissionId: id, locale: document.documentElement.lang || 'en' }),
      });
    try {
      let res = await send(clientSubmissionId);
      if (res.status === 409) {
        // Content changed after an earlier attempt with this token: send as a new inquiry.
        const fresh = crypto.randomUUID();
        setClientSubmissionId(fresh);
        res = await send(fresh);
      }
      const json = await res.json();
      if (json.ok) return setState({ kind: 'sent' });
      if (res.status === 422) setClientSubmissionId(crypto.randomUUID()); // rejected, nothing stored: next try is new
      setState({ kind: 'error', message: json.message ?? 'Something went wrong.', fields: json.fields });
    } catch {
      setState({ kind: 'error', message: 'Network problem. Please try again.' });
    }
  }

  if (state.kind === 'sent') {
    return <p role="status">Thank you. Your inquiry has been received.</p>;
  }
  const err = state.kind === 'error' ? state.fields ?? {} : {};
  return (
    <form onSubmit={onSubmit} noValidate>
      <input type="text" name="website" tabIndex={-1} autoComplete="off" hidden aria-hidden="true" />
      <label>Name <input name="name" required maxLength={190} autoComplete="name" /></label>
      <label>Company <input name="company" maxLength={190} autoComplete="organization" /></label>
      <label>Email <input name="email" type="email" required maxLength={190} autoComplete="email" aria-invalid={Boolean(err.email)} /></label>
      {err.email && <span role="alert">{err.email}</span>}
      <label>Phone <input name="phone" type="tel" maxLength={50} autoComplete="tel" /></label>
      <label>Message <textarea name="message" rows={5} maxLength={20000} /></label>
      {state.kind === 'error' && <p role="alert">{state.message}</p>}
      <button type="submit" disabled={state.kind === 'sending'}>{state.kind === 'sending' ? 'Sending…' : 'Send inquiry'}</button>
    </form>
  );
}
