<?php
namespace Eb\Identity;

/** REST v2 handlers for Identity & Access (2.3.0). */
final class Http
{
    private static function body(array $req): array { return is_array($req['body'] ?? null) ? $req['body'] : []; }

    public static function catalog(array $req, array $p): array { return \Rest::envelope(200, Catalog::all()); }
    public static function roles(array $req, array $p): array { return \Rest::envelope(200, Api::listRoles()); }
    public static function role(array $req, array $p): array { return \Rest::envelope(200, Api::getRole((int)$p['id'])); }
    public static function createRole(array $req, array $p): array { return \Rest::envelope(201, Api::createRole(self::body($req), (int)\Auth::id())); }
    public static function updateRole(array $req, array $p): array { return \Rest::envelope(200, Api::updateRole((int)$p['id'], self::body($req), (int)\Auth::id())); }
    public static function deleteRole(array $req, array $p): array { Api::deleteRole((int)$p['id'], (int)\Auth::id()); return \Rest::envelope(200, ['deleted' => (int)$p['id']]); }
    public static function users(array $req, array $p): array { return \Rest::envelope(200, Api::users()); }
    public static function effective(array $req, array $p): array { return \Rest::envelope(200, Api::effectiveFor((int)$p['id'])); }

    public static function setUserRoles(array $req, array $p): array
    {
        $b = self::body($req);
        if (!is_array($b['role_ids'] ?? null)) throw new \RestError(400, 'validation_error', 'role_ids باید فهرست باشد', 'role_ids');
        return \Rest::envelope(200, Api::setUserRoles((int)$p['id'], $b['role_ids'], (int)\Auth::id()));
    }

    public static function setUserScopedGrants(array $req, array $p): array
    {
        $b = self::body($req);
        if (!is_array($b['grants'] ?? null)) throw new \RestError(400, 'validation_error', 'grants باید فهرست باشد', 'grants');
        return \Rest::envelope(200, Api::setUserScopedGrants((int)$p['id'], $b['grants'], (int)\Auth::id()));
    }

    /** The caller's own effective permissions (any logged-in user). */
    public static function me(array $req, array $p): array
    {
        return \Rest::envelope(200, ['keys' => \Auth::keys()]);
    }
}
