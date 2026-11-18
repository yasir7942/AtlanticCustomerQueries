<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotFound;
use App\Domain\ValidationError;
use App\Http\Response;

final class WebsiteController
{
    public function index(Context $ctx): Response
    {
        return $ctx->render('websites/index', ['title' => 'Websites', 'websites' => $ctx->websites->all()]);
    }

    public function create(Context $ctx, array $errors = [], array $old = [], int $status = 200): Response
    {
        return $ctx->render('websites/create', ['title' => 'Register a website', 'errors' => $errors, 'old' => $old], $status);
    }

    public function store(Context $ctx): Response
    {
        $input = $this->input($ctx, ['name', 'domain', 'platform', 'business_contact', 'business_email', 'notes', 'rate_limit_per_minute']);
        try {
            $site = $ctx->websites->create($ctx->me(), $input);
        } catch (ValidationError $e) {
            return $this->create($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('success', 'Website registered. Create its API key below.');
        return $ctx->redirect('/websites/' . $site['id'] . '/connect');
    }

    public function show(Context $ctx, array $errors = [], array $old = [], int $status = 200): Response
    {
        $id = (int) $ctx->request->params['id'];
        $site = $ctx->websites->find($id) ?? throw new NotFound();
        $stats = $ctx->db->one(
            'SELECT COUNT(*) AS total, MAX(received_at) AS last_at FROM submissions WHERE website_id = ?',
            [$id]
        );
        $assigned = $ctx->db->all(
            'SELECT u.id, u.name, u.email, u.status FROM website_user wu JOIN users u ON u.id = wu.user_id WHERE wu.website_id = ? ORDER BY u.name',
            [$id]
        );
        return $ctx->render('websites/show', [
            'title' => $site['name'],
            'site' => $site,
            'stats' => $stats,
            'assigned' => $assigned,
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }

    public function updateBusiness(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $input = $this->input($ctx, ['name', 'business_contact', 'business_email', 'notes']);
        try {
            $ctx->websites->updateBusiness($ctx->me(), $id, $input);
        } catch (ValidationError $e) {
            return $this->show($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('success', 'Business details saved.');
        return $ctx->redirect('/websites/' . $id);
    }

    public function updateConnection(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $input = $this->input($ctx, ['domain', 'platform', 'rate_limit_per_minute']);
        try {
            $ctx->websites->updateConnection($ctx->me(), $id, $input);
        } catch (ValidationError $e) {
            return $this->show($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('success', 'Connection settings saved.');
        return $ctx->redirect('/websites/' . $id);
    }

    public function setStatus(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        $active = $ctx->request->input('status') === 'active';
        try {
            $ctx->websites->setActive($ctx->me(), $id, $active);
        } catch (ValidationError $e) {
            $ctx->flash('error', implode(' ', $e->errors));
            return $ctx->redirect('/websites/' . $id);
        }
        $ctx->flash('success', $active ? 'Website activated. Its keys work again.' : 'Website deactivated. Submissions are rejected until it is activated.');
        return $ctx->redirect('/websites/' . $id);
    }

    public function delete(Context $ctx): Response
    {
        $id = (int) $ctx->request->params['id'];
        try {
            $result = $ctx->websites->remove($ctx->me(), $id, $ctx->request->input('confirm_domain'));
        } catch (ValidationError $e) {
            $ctx->flash('error', implode(' ', $e->errors));
            return $ctx->redirect('/websites/' . $id);
        }
        $messages = [
            'deleted' => 'Website deleted. It had no inquiries.',
            'archived' => 'Website archived. Its inquiries remain readable and all of its keys were revoked.',
            'purged' => 'Website and all of its inquiries were permanently deleted.',
        ];
        $ctx->flash('success', $messages[$result]);
        return $result === 'archived' ? $ctx->redirect('/websites/' . $id) : $ctx->redirect('/websites');
    }

    private function input(Context $ctx, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $ctx->request->post)) {
                $out[$k] = $ctx->request->input($k);
            }
        }
        return $out;
    }
}
