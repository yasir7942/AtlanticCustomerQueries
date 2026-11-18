<?php

namespace App\Controllers;

use App\Libraries\InquiryForwarder;

class Inquiry extends BaseController
{
    /** GET /contact — render the form with a one-time submission token. */
    public function new()
    {
        session()->set('inquiry_token', InquiryForwarder::newSubmissionId());
        return view('inquiry_form');
    }

    /** POST /contact */
    public function create()
    {
        $rules = [
            'name' => 'required|max_length[190]',
            'email' => 'required|valid_email|max_length[190]',
            'phone' => 'permit_empty|max_length[50]',
            'company' => 'permit_empty|max_length[190]',
            'message' => 'permit_empty|max_length[20000]',
            'form_type' => 'required|in_list[contact,quote,sample]',
        ];
        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }
        $p = $this->request->getPost();
        $payload = array_filter([
            'submission_id' => session()->get('inquiry_token') ?? InquiryForwarder::newSubmissionId(),
            'form_type' => $p['form_type'],
            'form_id' => 'ci4-' . $p['form_type'],
            'locale' => service('request')->getLocale(),
            'full_name' => trim($p['name']),
            'company' => trim($p['company'] ?? ''),
            'email' => trim($p['email']),
            'phone' => trim($p['phone'] ?? ''),
            'message' => trim($p['message'] ?? ''),
            'source' => ['page_url' => previous_url(), 'ip' => $this->request->getIPAddress()],
        ], fn ($v) => $v !== '' && $v !== null);

        $forwarder = new InquiryForwarder();
        $result = $forwarder->send($payload);
        if (!$result['ok'] && $result['retryable']) {
            $forwarder->queue($payload);          // retried by `php spark inquiry:retry`
        } elseif (!$result['ok'] && $result['status'] !== 409) {
            session()->set('inquiry_token', InquiryForwarder::newSubmissionId()); // nothing stored; corrected form is new
            log_message('warning', 'Inquiry rejected: HTTP {status} fields {fields}', ['status' => $result['status'], 'fields' => implode(',', array_keys($result['fields']))]);
            return redirect()->back()->withInput()->with('errors', $result['fields']);
        }
        session()->remove('inquiry_token');
        return redirect()->to('/thank-you');
    }
}
