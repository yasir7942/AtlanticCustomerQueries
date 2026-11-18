<?php

declare(strict_types=1);

namespace App\Support;

final class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function valid(?string $submitted): bool
    {
        $token = $this->session->get('_csrf');
        return is_string($token) && is_string($submitted) && $submitted !== '' && hash_equals($token, $submitted);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->token()) . '">';
    }
}
