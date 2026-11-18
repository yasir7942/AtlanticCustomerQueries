<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Domain\AuditLog;
use App\Domain\CredentialService;
use App\Domain\Scope;
use App\Domain\Submissions\SubmissionQuery;
use App\Domain\UserService;
use App\Domain\WebsiteService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Session;
use App\Support\Time;
use App\Support\View;

/**
 * Per-request services handed to web controllers.
 */
final class Context
{
    private ?Scope $scope = null;

    public function __construct(
        public readonly Request $request,
        public readonly Config $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Csrf $csrf,
        public readonly Auth $auth,
        public readonly AuditLog $audit,
        public readonly View $view,
        public readonly UserService $users,
        public readonly WebsiteService $websites,
        public readonly CredentialService $credentials,
    ) {
    }

    public function user(): ?array
    {
        return $this->auth->user();
    }

    /** Current user; only call on authenticated routes. */
    public function me(): array
    {
        return $this->auth->user() ?? throw new \LogicException('No authenticated user');
    }

    public function can(string $ability): bool
    {
        return Gate::allows($this->auth->user(), $ability);
    }

    public function timezone(): string
    {
        return (string) ($this->auth->user()['timezone'] ?? Time::DEFAULT_ZONE);
    }

    /** Fresh per request: assignment changes apply on the next request. */
    public function scope(): Scope
    {
        return $this->scope ??= Scope::for($this->me(), $this->db);
    }

    public function inquiries(): SubmissionQuery
    {
        return new SubmissionQuery($this->db, $this->scope(), $this->timezone());
    }

    public function url(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        return $this->config->get('base_path') . $path . ($query ? '?' . http_build_query($query) : '');
    }

    public function absoluteUrl(string $path): string
    {
        return $this->config->get('url') . $path;
    }

    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $data += ['title' => '', 'errors' => [], 'old' => []];
        $layout = $data['_layout'] ?? 'layout';
        return Response::html($this->view->render($template, $data + ['ctx' => $this, 'flash' => $this->takeFlash()], $layout), $status);
    }

    public function redirect(string $path, array $query = []): Response
    {
        return Response::redirect($this->url($path, $query));
    }

    public function flash(string $type, string $message): void
    {
        $this->session->set('flash', ['type' => $type, 'message' => $message]);
    }

    /** One-time data for the next request (e.g. a newly generated key or temporary password). */
    public function flashSecret(string $key, string $value): void
    {
        $this->session->set('secret:' . $key, $value);
    }

    public function takeSecret(string $key): ?string
    {
        $v = $this->session->get('secret:' . $key);
        $this->session->remove('secret:' . $key);
        return is_string($v) ? $v : null;
    }

    private function takeFlash(): ?array
    {
        $f = $this->session->get('flash');
        $this->session->remove('flash');
        return is_array($f) ? $f : null;
    }
}
