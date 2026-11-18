<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Role capabilities. Server-side checks use this class; navigation reads the same rules.
 */
final class Gate
{
    public const ROLES = ['developer', 'director', 'user'];

    private const ABILITIES = [
        'inquiries.read' => ['developer', 'director', 'user'],
        'profile.manage' => ['developer', 'director', 'user'],
        'users.manage' => ['developer', 'director'],
        'websites.view' => ['developer', 'director'],
        'websites.assign' => ['developer', 'director'],
        'websites.edit_business' => ['developer', 'director'],
        'websites.lifecycle' => ['developer'],      // register, delete/archive, deactivate
        'websites.connection' => ['developer'],     // connection settings, keys, guides, connector downloads
        'audit.read' => ['developer', 'director'],
        'diagnostics.read' => ['developer'],
    ];

    public static function allows(?array $user, string $ability): bool
    {
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            return false;
        }
        return in_array($user['role'], self::ABILITIES[$ability] ?? [], true);
    }

    /** Roles the actor may assign when creating or editing an account. */
    public static function grantableRoles(array $actor): array
    {
        return match ($actor['role']) {
            'developer' => ['developer', 'director', 'user'],
            'director' => ['director', 'user'],
            default => [],
        };
    }

    /** Whether the actor may modify (edit, deactivate, reset password, assign) the target account. */
    public static function canManageUser(array $actor, array $target): bool
    {
        if (!self::allows($actor, 'users.manage')) {
            return false;
        }
        return in_array($target['role'], self::grantableRoles($actor), true);
    }

    public static function seesAllWebsites(array $user): bool
    {
        return in_array($user['role'], ['developer', 'director'], true);
    }
}
