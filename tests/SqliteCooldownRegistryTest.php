<?php

declare(strict_types=1);

use Tetthys\Pie\Support\SqliteCooldownRegistry;

it('stores, reads and expires cooldowns', function () {
    $f = sys_get_temp_dir() . '/pie_cd_' . bin2hex(random_bytes(4)) . '.sqlite';
    $reg = new SqliteCooldownRegistry($f);
    $id = 'ID-X';
    expect($reg->getCooldownUntil($id))->toBeNull();

    $until = time() + 1;
    $reg->setCooldown($id, $until);
    expect($reg->getCooldownUntil($id))->toBe($until);

    sleep(2);
    expect($reg->getCooldownUntil($id))->toBeNull();
    @unlink($f);
});
