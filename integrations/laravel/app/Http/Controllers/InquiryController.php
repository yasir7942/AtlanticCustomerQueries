<?php

namespace App\Http\Controllers;

use App\Jobs\ForwardInquiry;
use App\Services\InquiryForwarder;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'form_type' => ['required', 'in:contact,quote,sample'],
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:20000'],
            'products' => ['nullable', 'array', 'max:50'],
            'products.*.name' => ['required_with:products', 'string', 'max:200'],
            'products.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'needed_by' => ['nullable', 'date'],
            // Hidden input generated once per form view, so a double submit reuses the same ID.
            'inquiry_token' => ['required', 'uuid'],
        ]);
        $token = 'laravel-' . strtolower($data['inquiry_token']);

        $payload = array_filter([
            'submission_id' => $token,
            'form_type' => $data['form_type'],
            'form_id' => 'laravel-' . $data['form_type'],
            'locale' => app()->getLocale(),
            'full_name' => $data['name'],
            'company' => $data['company'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'message' => $data['message'] ?? null,
            'products' => array_values(array_map(
                fn ($p) => array_filter(['name' => $p['name'], 'quantity' => isset($p['quantity']) ? (float) $p['quantity'] : null], fn ($v) => $v !== null),
                $data['products'] ?? []
            )),
            'fields' => isset($data['needed_by']) ? ['needed_by' => ['label' => 'Needed by', 'value' => $data['needed_by']]] : null,
            'source' => ['page_url' => url()->previous(), 'ip' => $request->ip()],
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        // With a queue worker or cron-driven queue (see guide) the visitor never waits on the API.
        ForwardInquiry::dispatch($payload);

        return redirect()->back()->with('status', 'Thank you. Your inquiry has been received.');
    }

    /** Show the form with a one-time token: <input type="hidden" name="inquiry_token" value="{{ $token }}"> */
    public function create()
    {
        return view('inquiry.create', ['token' => (string) \Illuminate\Support\Str::uuid()]);
    }
}
