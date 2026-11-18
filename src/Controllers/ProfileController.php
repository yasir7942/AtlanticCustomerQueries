<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ValidationError;
use App\Http\Response;

final class ProfileController
{
    public function show(Context $ctx, array $errors = [], array $pwErrors = [], int $status = 200): Response
    {
        return $ctx->render('profile', ['title' => 'Your profile', 'errors' => $errors, 'pwErrors' => $pwErrors], $status);
    }

    public function update(Context $ctx): Response
    {
        try {
            $ctx->users->updateProfile($ctx->me(), $ctx->request->input('name'), $ctx->request->input('timezone'));
        } catch (ValidationError $e) {
            return $this->show($ctx, $e->errors, [], 422);
        }
        $ctx->flash('success', 'Profile saved.');
        return $ctx->redirect('/profile');
    }

    public function password(Context $ctx): Response
    {
        try {
            $ctx->users->changeOwnPassword(
                $ctx->me(),
                $ctx->request->input('current_password'),
                $ctx->request->input('password'),
                $ctx->request->input('password_confirm'),
            );
        } catch (ValidationError $e) {
            return $this->show($ctx, [], $e->errors, 422);
        }
        $ctx->auth->refresh();
        $ctx->flash('success', 'Password changed. Your other sessions were signed out.');
        return $ctx->redirect('/profile');
    }
}
