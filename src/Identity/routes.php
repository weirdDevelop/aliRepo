<?php
use Eb\Identity\Http;

// identity.role.manage is admin-only in the catalogue, so these are administrator endpoints.
return [
    ['GET',    '/v2/identity/me/permissions',     [Http::class, 'me'],           'any'],
    ['GET',    '/v2/identity/permissions',        [Http::class, 'catalog'],      'identity.role.manage'],
    ['GET',    '/v2/identity/roles',              [Http::class, 'roles'],        'identity.role.manage'],
    ['POST',   '/v2/identity/roles',              [Http::class, 'createRole'],   'identity.role.manage'],
    ['GET',    '/v2/identity/roles/{id}',         [Http::class, 'role'],         'identity.role.manage'],
    ['PATCH',  '/v2/identity/roles/{id}',         [Http::class, 'updateRole'],   'identity.role.manage'],
    ['DELETE', '/v2/identity/roles/{id}',         [Http::class, 'deleteRole'],   'identity.role.manage'],
    ['GET',    '/v2/identity/users',              [Http::class, 'users'],        'identity.role.manage'],
    ['GET',    '/v2/identity/users/{id}/effective', [Http::class, 'effective'],  'identity.role.manage'],
    ['PUT',    '/v2/identity/users/{id}/roles',   [Http::class, 'setUserRoles'], 'identity.role.manage'],
];
