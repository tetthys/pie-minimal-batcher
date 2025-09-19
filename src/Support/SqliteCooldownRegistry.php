<?php

declare(strict_types=1);

namespace Tetthys\Pie\Support;

use Tetthys\Pie\Contracts\CooldownRegistryInterface;
use PDO;

/**
 * SQLite-backed cooldown registry.
 * Table: cooldowns(identity TEXT PRIMARY KEY, until INTEGER NOT NULL)
 */
final class SqliteCooldownRegistry implements CooldownRegistryInterface
{
    private PDO $pdo;
    public function __construct(string $file)
    {
        @is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
        $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS cooldowns(identity TEXT PRIMARY KEY, until INTEGER NOT NULL)');
    }
    public function setCooldown(string $identity, int $untilEpoch): void
    {
        $st = $this->pdo->prepare('INSERT INTO cooldowns(identity, until) VALUES(?, ?)
                                   ON CONFLICT(identity) DO UPDATE SET until=excluded.until');
        $st->execute([$identity, $untilEpoch]);
    }
    public function getCooldownUntil(string $identity): ?int
    {
        $st = $this->pdo->prepare('SELECT until FROM cooldowns WHERE identity=?');
        $st->execute([$identity]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $until = (int)$row['until'];
        if ($until <= time()) {
            $this->pdo->prepare('DELETE FROM cooldowns WHERE identity=?')->execute([$identity]);
            return null;
        }
        return $until;
    }
    public function clearIfExpired(string $identity): void
    {
        $this->pdo->prepare('DELETE FROM cooldowns WHERE identity=? AND until<=?')->execute([$identity, time()]);
    }
}
