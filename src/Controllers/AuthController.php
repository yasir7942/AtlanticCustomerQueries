<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ValidationError;
use App\Http\Response;

final class AuthController
{
    public function showLogin(Context $ctx): Response
    {
        return $ctx->render('auth/login', ['_layout' => 'layout_bare', 'title' => 'Sign in', 'email' => '', 'remember' => true]);
    }

    public function login(Context $ctx): Response
    {
        $email = $ctx->request->input('email');
        $password = $ctx->request->input('password');
        $ip = $ctx->request->ip((array) $ctx->config->get('trusted_proxies', []));
        if (strlen($email) > 190 || strlen($password) > 200) {
            return $ctx->render('auth/login', ['_layout' => 'layout_bare', 'title' => 'Sign in', 'email' => '', 'remember' => true, 'error' => 'The email or password is incorrect, or the account is inactive.'], 422);
        }
        // Read before signing in: a successful sign-in starts a fresh session (fixation protection).
        $intended = $ctx->session->get('intended');
        $remember = $ctx->request->input('remember') === '1';
        $result = $ctx->auth->attempt($email, $password, $ip, $remember);
        if (!$result['ok']) {
            return $ctx->render('auth/login', ['_layout' => 'layout_bare', 'title' => 'Sign in', 'email' => $email, 'remember' => $remember, 'error' => $result['error']], 422);
        }
        $user = $ctx->me();
        if ((int) $user['must_change_password'] === 1) {
            return $ctx->redirect('/password/change');
        }
        // Only same-application relative paths are accepted (no open redirect).
        if (is_string($intended) && preg_match('#^/[A-Za-z0-9/_\-?=&%.+,]*$#', $intended) && !str_starts_with($intended, '//')) {
            return Response::redirect($ctx->url('') . $intended);
        }
        return $ctx->redirect('/');
    }

    public function logout(Context $ctx): Response
    {
        $ctx->auth->logout();
        $ctx->flash('info', 'You have signed out.');
        return $ctx->redirect('/login');
    }

    public function showChange(Context $ctx): Response
    {
        return $ctx->render('auth/change-password', ['title' => 'Change password', 'forced' => (int) $ctx->me()['must_change_password'] === 1]);
    }

    public function change(Context $ctx): Response
    {
        $user = $ctx->me();
        try {
            $ctx->users->changeOwnPassword(
                $user,
                $ctx->request->input('current_password'),
                $ctx->request->input('password'),
                $ctx->request->input('password_confirm'),
            );
        } catch (ValidationError $e) {
            return $ctx->render('auth/change-password', [
                'title' => 'Change password', 'forced' => (int) $user['must_change_password'] === 1, 'errors' => $e->errors,
            ], 422);
        }
        // Other sessions are invalidated by the session_version bump; keep this one with a new ID.
        $ctx->auth->refresh();
        $ctx->flash('success', 'Your password was changed. Other signed-in sessions were signed out.');
        return $ctx->redirect('/');
    }
}
