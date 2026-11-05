<?php
namespace Eb\Organization;

/** REST v2 handlers for the Organization context (2.4.0). */
final class Http
{
    /** Request body without internal keys (anything starting with "_"). */
    private static function body(array $req): array
    {
        $b = is_array($req['body'] ?? null) ? $req['body'] : [];
        return array_filter($b, fn($k) => !(is_string($k) && strpos($k, '_') === 0), ARRAY_FILTER_USE_KEY);
    }
    private static function actor(): int { return (int)\Auth::id(); }

    public static function tree(array $req, array $p): array
    {
        return \Rest::envelope(200, Api::tree($req['query']['date'] ?? null, !empty($req['query']['archived']) && \Auth::can('org.unit.manage')));
    }
    public static function unit(array $req, array $p): array { return \Rest::envelope(200, Api::unit((int)$p['id'])); }
    public static function create(array $req, array $p): array { return \Rest::envelope(201, Api::createUnit(self::body($req), self::actor())); }
    public static function update(array $req, array $p): array { return \Rest::envelope(200, Api::updateUnit((int)$p['id'], self::body($req), self::actor())); }
    public static function archive(array $req, array $p): array { return \Rest::envelope(200, Api::archiveUnit((int)$p['id'], self::actor())); }
    public static function restore(array $req, array $p): array { return \Rest::envelope(200, Api::restoreUnit((int)$p['id'], self::actor())); }
    public static function addMember(array $req, array $p): array { return \Rest::envelope(201, Api::addMember((int)$p['id'], self::body($req), self::actor())); }
    public static function endMember(array $req, array $p): array { return \Rest::envelope(200, Api::endMember((int)$p['id'], self::body($req)['valid_to'] ?? null, self::actor())); }
    public static function delegate(array $req, array $p): array { return \Rest::envelope(201, Api::delegate((int)$p['id'], self::body($req), self::actor())); }
    public static function revokeDelegation(array $req, array $p): array { return \Rest::envelope(200, Api::revokeDelegation((int)$p['id'], self::actor())); }
    public static function chain(array $req, array $p): array { return \Rest::envelope(200, Api::reportingChain((int)$p['id'], $req['query']['date'] ?? null)); }
    public static function myChain(array $req, array $p): array { return \Rest::envelope(200, Api::reportingChain(self::actor(), $req['query']['date'] ?? null)); }
    public static function importPreview(array $req, array $p): array { return \Rest::envelope(200, Import::preview()); }
    public static function importApply(array $req, array $p): array
    {
        $b = self::body($req);
        return \Rest::envelope(200, Import::apply(is_array($b['sources'] ?? null) ? $b['sources'] : [], self::actor()));
    }
}
