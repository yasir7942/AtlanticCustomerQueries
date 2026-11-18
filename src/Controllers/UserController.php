<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Gate;
use App\Domain\AccessDenied;
use App\Domain\NotFound;
use App\Domain\ValidationError;
use App\Http\Response;

final class UserController
{
    public function index(Context $ctx): Response
    {
        return $ctx->render('users/index', ['title' => 'Users', 'users' => $ctx->users->all()]);
    }

    public function create(Context $ctx, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $ctx->render('users/create', [
            'title' => 'Create an account',
            'roles' => Gate::grantableRoles($ctx->me()),
            'errors' => $errors,
            'old' => $old + ['role' => 'user', 'timezone' => 'Asia/Dubai', 'password_mode' => 'temporary'],
        ], $status);
    }

    public function store(Context $ctx): Response
    {
        $p = $ctx->request->post;
        $input = [
            'name' => $ctx->request->input('name'),
            'email' => $ctx->request->input('email'),
            'role' => $ctx->request->input('role', 'user'),
            'timezone' => $ctx->request->input('timezone', 'Asia/Dubai'),
            'password_mode' => $ctx->request->input('password_mode', 'temporary'),
            'password' => $ctx->request->input('password'),
            'password_confirm' => $ctx->request->input('password_confirm'),
            'require_change' => !empty($p['require_change']),
        ];
        try {
            $result = $ctx->users->create($ctx->me(), $input);
        } catch (ValidationError $e) {
            unset($input['password'], $input['password_confirm']);
            return $this->create($ctx, $e->errors, $input, 422);
        }
        $id = (int) $result['user']['id'];
        if ($result['temporary_password'] !== null) {
            $ctx->flashSecret('temp:' . $id, $result['temporary_password']);
        }
        $ctx->flash('success', 'Account created.' . ($result['user']['role'] === 'user' ? ' Assign websites so this user can see inquiries.' : ''));
        return $ctx->redirect('/users/' . $id);
    }

    public function edit(Context $ctx, array $errors = [], int $status = 200): Response
    {
        $id = (int) $ctx->request->params['id'];
        $target = $ctx->users->find($id) ?? throw new NotFound();
        $me = $ctx->me();
        return $ctx->render('users/edit', [
            'title' => $target['name'],
            'target' => $target,
            'canManage' => Gate::canManageUser($me, $target),
            'roles' => Gate::grantableRoles($me),
            'isSelf' => (int) $me['id'] === $id,
            'websites' => $ctx->db->all('SELECT id, name, domain, status FROM websites ORDER BY status = "archived", name'),
            'assigned' => $ctx->users->assignedWebsiteIds($id),
            'tempPassword' => $ctx->takeSecret('temp:' . $id),
            'errors' => $errors,
        ], $status);
    }

    public function update(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        try {
            $ctx->users->update($ctx->me(), $id, [
                'name' => $ctx->request->input('name'),
                'email' => $ctx->request->input('email'),
                'role' => $ctx->request->input('role'),
                'status' => $ctx->request->input('status'),
            ]);
        } catch (ValidationError $e) {
            return $this->edit($ctx, $e->errors, 422);
        }
        $ctx->flash('success', 'Account saved.');
        return $ctx->redirect('/users/' . $id);
    }

    public function resetPassword(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        if ((int) $ctx->me()['id'] === $id) {
            $ctx->flash('info', 'Change your own password from your profile.');
            return $ctx->redirect('/profile');
        }
        try {
            $temp = $ctx->users->resetPassword($ctx->me(), $id, [
                'password_mode' => $ctx->request->input('password_mode', 'temporary'),
                'password' => $ctx->request->input('password'),
                'password_confirm' => $ctx->request->input('password_confirm'),
                'require_change' => !empty($ctx->request->post['require_change']),
            ]);
        } catch (ValidationError $e) {
            return $this->edit($ctx, $e->errors, 422);
        }
        if ($temp !== null) {
            $ctx->flashSecret('temp:' . $id, $temp);
        }
        $ctx->flash('success', 'Password reset. All of this account\'s sessions were signed out.');
        return $ctx->redirect('/users/' . $id);
    }

    public function assign(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        try {
            $ctx->users->setAssignments($ctx->me(), $id, array_map('intval', $ctx->request->inputList('websites')));
        } catch (ValidationError $e) {
            return $this->edit($ctx, $e->errors, 422);
        }
        $ctx->flash('success', 'Website access saved. It applies from the user\'s next page load.');
        return $ctx->redirect('/users/' . $id);
    }
}
