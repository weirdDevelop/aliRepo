<?php
use Eb\Organization\Http;

// Reading the chart is open to every logged-in user (names, units, managers — no contact data).
// Writes are authorised inside the service per unit (Auth::canIn on org.unit.manage / org.member.manage),
// so a role granted on one department's subtree can manage only that subtree.
return [
    ['GET',  '/v2/org/units',                         [Http::class, 'tree'],             'any'],
    ['GET',  '/v2/org/units/{id}',                    [Http::class, 'unit'],             'any'],
    ['POST', '/v2/org/units',                         [Http::class, 'create'],           'any'],
    ['PATCH','/v2/org/units/{id}',                    [Http::class, 'update'],           'any'],
    ['POST', '/v2/org/units/{id}/archive',            [Http::class, 'archive'],          'any'],
    ['POST', '/v2/org/units/{id}/restore',            [Http::class, 'restore'],          'any'],
    ['POST', '/v2/org/units/{id}/members',            [Http::class, 'addMember'],        'any'],
    ['POST', '/v2/org/memberships/{id}/end',          [Http::class, 'endMember'],        'any'],
    ['POST', '/v2/org/units/{id}/delegations',        [Http::class, 'delegate'],         'any'],
    ['POST', '/v2/org/delegations/{id}/revoke',       [Http::class, 'revokeDelegation'], 'any'],
    ['GET',  '/v2/org/users/{id}/reporting-chain',    [Http::class, 'chain'],            'any'],
    ['GET',  '/v2/org/me/reporting-chain',            [Http::class, 'myChain'],          'any'],
    ['GET',  '/v2/org/import/preview',                [Http::class, 'importPreview'],    'org.unit.manage'],
    ['POST', '/v2/org/import',                        [Http::class, 'importApply'],      'org.unit.manage'],
];
