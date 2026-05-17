<?php
declare(strict_types=1);

/**
 * SilverPoints packs (demo pricing — no real payment processor).
 */

/** @return list<array{id:string,title:string,points:int,usd_cents:int,blurb:string}> */
function carenest_points_packages(): array {
    return [
        [
            'id' => 'pack_starter',
            'title' => 'Starter',
            'points' => 500,
            'usd_cents' => 999,
            'blurb' => 'Great for a few extra visits.',
        ],
        [
            'id' => 'pack_plus',
            'title' => 'Plus',
            'points' => 1500,
            'usd_cents' => 2499,
            'blurb' => 'Better value for regular care weeks.',
        ],
        [
            'id' => 'pack_family',
            'title' => 'Family',
            'points' => 5000,
            'usd_cents' => 6999,
            'blurb' => 'For ongoing support and peace of mind.',
        ],
    ];
}

/** @return array{points:int,usd_cents:int,title:string}|null */
function carenest_points_package_by_id(string $id): ?array {
    foreach (carenest_points_packages() as $p) {
        if ($p['id'] === $id) {
            return [
                'points' => (int) $p['points'],
                'usd_cents' => (int) $p['usd_cents'],
                'title' => (string) $p['title'],
            ];
        }
    }

    return null;
}
