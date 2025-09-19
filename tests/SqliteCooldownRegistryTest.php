<?php

declare(strict_types=1);

use Tetthys\Pie\Support\SqliteCooldownRegistry;

describe('SqliteCooldownRegistry', function () {

    it('returns null for non-existing identity and persists a new cooldown', function () {
        $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
        $reg = new SqliteCooldownRegistry($f);

        $id = 'ID-A';
        expect($reg->getCooldownUntil($id))->toBeNull();

        $until = time() + 2;
        $reg->setCooldown($id, $until);
        expect($reg->getCooldownUntil($id))->toBe($until);

        @unlink($f);
    });

    it('supports upsert: updating the same identity overwrites previous value', function () {
        $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
        $reg = new SqliteCooldownRegistry($f);

        $id = 'ID-B';
        $t1 = time() + 10;
        $t2 = time() + 20;

        $reg->setCooldown($id, $t1);
        expect($reg->getCooldownUntil($id))->toBe($t1);

        // Overwrite with a new timestamp
        $reg->setCooldown($id, $t2);
        expect($reg->getCooldownUntil($id))->toBe($t2);

        @unlink($f);
    });

    it('expires entries either immediately (past timestamp) or after short wait', function () {
        $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
        $reg = new SqliteCooldownRegistry($f);

        $idPast = 'ID-P';
        $idSoon = 'ID-S';

        // Immediate expiry: set until in the past
        $reg->setCooldown($idPast, time() - 1);
        expect($reg->getCooldownUntil($idPast))->toBeNull();

        // Short future then wait
        $until = time() + 1;
        $reg->setCooldown($idSoon, $until);
        expect($reg->getCooldownUntil($idSoon))->toBe($until);

        sleep(2); // allow to expire
        expect($reg->getCooldownUntil($idSoon))->toBeNull();

        @unlink($f);
    });

    it('clearIfExpired removes only expired identity and keeps valid ones', function () {
        $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
        $reg = new SqliteCooldownRegistry($f);

        $expired = 'ID-OLD';
        $valid   = 'ID-NEW';

        $reg->setCooldown($expired, time() - 5);
        $reg->setCooldown($valid, time() + 100);

        // Clear should only remove expired one
        $reg->clearIfExpired($expired);

        expect($reg->getCooldownUntil($expired))->toBeNull()
            ->and($reg->getCooldownUntil($valid))->toBeGreaterThan(time());

        @unlink($f);
    });

    it('handles many identities and preserves isolation', function () {
        $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
        $reg = new SqliteCooldownRegistry($f);

        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = "ID-$i";
        }

        // Set alternating past/future expirations
        foreach ($ids as $i => $id) {
            $until = ($i % 2 === 0) ? time() + 50 : time() - 50;
            $reg->setCooldown($id, $until);
        }

        // Validate even indices are valid, odd are expired
        foreach ($ids as $i => $id) {
            if ($i % 2 === 0) {
                expect($reg->getCooldownUntil($id))->toBeGreaterThan(time());
            } else {
                expect($reg->getCooldownUntil($id))->toBeNull();
            }
        }

        @unlink($f);
    });
});
