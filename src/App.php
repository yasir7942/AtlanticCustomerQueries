<?php

declare(strict_types=1);

namespace App;

use App\Auth\Auth;
use App\Auth\Gate;
use App\Auth\RememberMe;
use App\Support\CookieJar;
use App\Controllers;
use App\Controllers\Context;
use App\Domain\AccessDenied;
use App\Domain\AuditLog;
use App\Domain\CredentialService;
use App\Domain\NotFound;
use App\Domain\UserService;
use App\Domain\WebsiteService;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Database;
use App\Support\Logger;
use App\Support\NativeSession;
use App\Support\RateLimiter;
use App\Support\Session;
use App\Support\View;

/**
 * Application kernel: wiring, routing and request-level security checks.
 */
final class App
{
    public const VERSION = '1.0.0';

    private Router $router;
    private ?Database $db;
    public readonly Logger $logger;

    public function __construct(public readonly Config $config, ?Database $db = null)
    {
        $this->db = $db;
        $this->logger = new Logger($config->get('root') . '/storage/logs');
        $this->router = new Router();
        $this->routes();
    }

    public static function boot(string $root): self
    {
        return new self(Config::fromEnvFile($root));
    }

    public function db(): Database
    {
        return $this->db ??= Database::connect($this->config->get('db'));
    }

    private function routes(): void
    {
        $r = $this->router;
        // Guest
        $r->add('GET', '/login', [Controllers\AuthController::class, 'showLogin'], ['guest' => true]);
        $r->add('POST', '/login', [Controllers\AuthController::class, 'login'], ['guest' => true]);
        // Any signed-in user (password change allowed while a temporary password is pending)
        $r->add('POST', '/logout', [Controllers\AuthController::class, 'logout'], ['pending_ok' => true]);
        $r->add('GET', '/password/change', [Controllers\AuthController::class, 'showChange'], ['pending_ok' => true]);
        $r->add('POST', '/password/change', [Controllers\AuthController::class, 'change'], ['pending_ok' => true]);
        $r->add('GET', '/', [Controllers\DashboardController::class, 'index'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/inbox', [Controllers\InboxController::class, 'index'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/inbox/export', [Controllers\InboxController::class, 'export'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/inbox/{id}', [Controllers\InboxController::class, 'show'], ['ability' => 'inquiries.read']);
        $r->add('POST', '/inbox/delete-tests', [Controllers\InboxController::class, 'deleteTests'], ['ability' => 'inquiries.delete']);
        $r->add('POST', '/inbox/delete', [Controllers\InboxController::class, 'deleteSelected'], ['ability' => 'inquiries.delete']);
        $r->add('POST', '/inbox/{id}/delete', [Controllers\InboxController::class, 'destroy'], ['ability' => 'inquiries.delete']);
        $r->add('POST', '/inbox/{id}/status', [Controllers\InboxController::class, 'status'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/reports', [Controllers\ReportController::class, 'index'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/reports/export', [Controllers\ReportController::class, 'export'], ['ability' => 'inquiries.read']);
        $r->add('GET', '/profile', [Controllers\ProfileController::class, 'show'], ['ability' => 'profile.manage']);
        $r->add('POST', '/profile', [Controllers\ProfileController::class, 'update'], ['ability' => 'profile.manage']);
        $r->add('POST', '/profile/password', [Controllers\ProfileController::class, 'password'], ['ability' => 'profile.manage']);
        // Developers and Directors
        $r->add('GET', '/websites', [Controllers\WebsiteController::class, 'index'], ['ability' => 'websites.view']);
        $r->add('GET', '/websites/new', [Controllers\WebsiteController::class, 'create'], ['ability' => 'websites.lifecycle']);
        $r->add('POST', '/websites', [Controllers\WebsiteController::class, 'store'], ['ability' => 'websites.lifecycle']);
        $r->add('GET', '/websites/{id}', [Controllers\WebsiteController::class, 'show'], ['ability' => 'websites.view']);
        $r->add('POST', '/websites/{id}/business', [Controllers\WebsiteController::class, 'updateBusiness'], ['ability' => 'websites.edit_business']);
        $r->add('POST', '/websites/{id}/connection', [Controllers\WebsiteController::class, 'updateConnection'], ['ability' => 'websites.connection']);
        $r->add('POST', '/websites/{id}/status', [Controllers\WebsiteController::class, 'setStatus'], ['ability' => 'websites.lifecycle']);
        $r->add('POST', '/websites/{id}/delete', [Controllers\WebsiteController::class, 'delete'], ['ability' => 'websites.lifecycle']);
        $r->add('GET', '/websites/{id}/connect', [Controllers\ConnectController::class, 'show'], ['ability' => 'websites.connection']);
        $r->add('POST', '/websites/{id}/keys', [Controllers\ConnectController::class, 'issueKey'], ['ability' => 'websites.connection']);
        $r->add('POST', '/websites/{id}/keys/{key}/revoke', [Controllers\ConnectController::class, 'revokeKey'], ['ability' => 'websites.connection']);
        $r->add('GET', '/downloads/wordpress-plugin', [Controllers\ConnectController::class, 'downloadPlugin'], ['ability' => 'websites.connection']);
        $r->add('GET', '/users', [Controllers\UserController::class, 'index'], ['ability' => 'users.manage']);
        $r->add('GET', '/users/new', [Controllers\UserController::class, 'create'], ['ability' => 'users.manage']);
        $r->add('POST', '/users', [Controllers\UserController::class, 'store'], ['ability' => 'users.manage']);
        $r->add('GET', '/users/{id}', [Controllers\UserController::class, 'edit'], ['ability' => 'users.manage']);
        $r->add('POST', '/users/{id}', [Controllers\UserController::class, 'update'], ['ability' => 'users.manage']);
        $r->add('POST', '/users/{id}/password', [Controllers\UserController::class, 'resetPassword'], ['ability' => 'users.manage']);
        $r->add('POST', '/users/{id}/websites', [Controllers\UserController::class, 'assign'], ['ability' => 'websites.assign']);
        $r->add('GET', '/audit', [Controllers\AuditController::class, 'index'], ['ability' => 'audit.read']);
        $r->add('GET', '/diagnostics', [Controllers\AuditController::class, 'diagnostics'], ['ability' => 'diagnostics.read']);
    }

    public function handle(Request $request, ?Session $session = null): Response
    {
        try {
            if ($request->path === '/api' || str_starts_with($request->path, '/api/')) {
                return $this->withApiHeaders($this->handleApi($request));
            }
            return $this->withSecurityHeaders($this->handleWeb($request, $session), $request);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception', ['type' => $e::class, 'error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            if (str_starts_with($request->path, '/api/')) {
                return $this->withApiHeaders(Response::json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Unexpected server error. Retry with the same submission_id.']], 500));
            }
            $detail = $this->config->get('debug') ? $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null;
            return $this->withSecurityHeaders($this->errorPage(500, 'Something went wrong', 'The error was logged. Try again, or contact the Developer if it continues.', $detail), $request);
        }
    }

    private function handleApi(Request $request): Response
    {
        if ($request->path !== '/api/v1/submissions') {
            return Response::json(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Unknown endpoint. Use POST /api/v1/submissions.']], 404);
        }
        if ($request->method !== 'POST') {
            return Response::json(['ok' => false, 'error' => ['code' => 'method_not_allowed', 'message' => 'Use POST.']], 405, ['Allow' => 'POST']);
        }
        $db = $this->db();
        $audit = new AuditLog($db);
        $controller = new Controllers\ApiController($db, $this->config, new CredentialService($db, $audit), new RateLimiter($db), $this->logger);
        return $controller->submit($request);
    }

    private function handleWeb(Request $request, ?Session $session): Response
    {
        [$route, $params, $otherMethod] = $this->router->match($request->method, $request->path);
        if ($route === null) {
            return $otherMethod
                ? $this->errorPage(405, 'Method not allowed', 'This address does not accept that kind of request.')
                : $this->errorPage(404, 'Page not found', 'The page does not exist, or you do not have access to it.');
        }
        $request->params = $params;

        $proxies = (array) $this->config->get('trusted_proxies', []);
        $session ??= new NativeSession($this->config, $request->isSecure($proxies));
        $db = $this->db();
        $audit = new AuditLog($db, $request->ip($proxies));
        $credentials = new CredentialService($db, $audit);
        $csrf = new Csrf($session);
        $cookies = new CookieJar(
            ($this->config->get('base_path') ?: '') . '/',
            (bool) $this->config->get('session.secure') || $request->isSecure($proxies),
        );
        $rememberDays = (int) $this->config->get('session.remember_days');
        $remember = $rememberDays > 0 ? new RememberMe($db, $cookies, $request->cookies, $rememberDays) : null;
        $auth = new Auth($db, $session, new RateLimiter($db), $audit, (int) $this->config->get('session.idle_minutes'), $remember);
        $view = new View($this->config->get('root') . '/templates');
        $ctx = new Context(
            $request, $this->config, $db, $session, $csrf, $auth, $audit, $view,
            new UserService($db, $audit),
            new WebsiteService($db, $audit, $credentials),
            $credentials,
        );
        $view->share('ctx', $ctx);
        $view->share('appName', (string) $this->config->get('name'));

        $response = $this->dispatch($route, $request, $session, $csrf, $auth, $audit, $ctx, $view);
        // Cookies queued during the request (remember-me issue/clear) go out with any response.
        $response->cookies = $cookies->all() + $response->cookies;
        return $response;
    }

    private function dispatch(array $route, Request $request, Session $session, Csrf $csrf, Auth $auth, AuditLog $audit, Context $ctx, View $view): Response
    {
        if ($request->method === 'POST' && !$csrf->valid($request->input('_csrf'))) {
            return $this->errorPage(419, 'Form expired', 'The form was open too long or came from another site. Go back, reload the page and try again.', null, $view);
        }

        $options = $route['options'];
        $user = $auth->user();
        if (!empty($options['guest'])) {
            if ($user !== null) {
                return $ctx->redirect('/');
            }
        } else {
            if ($user === null) {
                if ($request->method === 'GET') {
                    $session->set('intended', $request->path . ($request->query ? '?' . http_build_query($request->query) : ''));
                }
                return $ctx->redirect('/login');
            }
            if ((int) $user['must_change_password'] === 1 && empty($options['pending_ok'])) {
                return $ctx->redirect('/password/change');
            }
            if (isset($options['ability']) && !Gate::allows($user, $options['ability'])) {
                $audit->record($user, 'access.denied', 'route', null, $request->method . ' ' . $request->path, 'denied', ['ability' => $options['ability']]);
                return $this->errorPage(403, 'Access denied', 'Your role does not allow this action.', null, $view);
            }
        }

        [$class, $method] = $route['handler'];
        try {
            return (new $class())->{$method}($ctx);
        } catch (AccessDenied $e) {
            return $this->errorPage(403, 'Access denied', $e->getMessage(), null, $view);
        } catch (NotFound $e) {
            return $this->errorPage(404, 'Not found', 'The record does not exist, or you do not have access to it.', null, $view);
        }
    }

    public function errorPage(int $status, string $title, string $message, ?string $detail = null, ?View $view = null): Response
    {
        $view ??= new View($this->config->get('root') . '/templates');
        $view->share('appName', (string) $this->config->get('name'));
        $html = $view->render('errors/error', [
            'status' => $status, 'title' => $title, 'message' => $message, 'detail' => $detail,
            'home' => $this->config->get('base_path') . '/',
        ], 'layout_bare');
        return Response::html($html, $status);
    }

    private function withSecurityHeaders(Response $response, Request $request): Response
    {
        $response->headers += [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if (!isset($response->headers['Cache-Control'])) {
            $response->headers['Cache-Control'] = 'no-store, private';
        }
        if ($request->isSecure((array) $this->config->get('trusted_proxies', []))) {
            $response->headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $response;
    }

    private function withApiHeaders(Response $response): Response
    {
        $response->headers += ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];
        return $response;
    }
}
