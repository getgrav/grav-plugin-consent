<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;
use RuntimeException;

/**
 * Proof that consent was given.
 *
 * GDPR Art. 7(1) requires a controller to be able to demonstrate that consent
 * was obtained. This is the part every third-party cookie plugin skips, and
 * without it a banner is decoration.
 *
 * Storage is monthly-rotated JSONL under `user/data/consent/`. One line per
 * decision, greppable, no database, no dependency. A site that wants records
 * in Postgres can subscribe to `onConsentChanged` and write them itself.
 *
 * The raw IP address and user agent are never stored. Each is hashed with a
 * per-site salt, which is what makes the log itself defensible: it evidences
 * that a decision happened without keeping an identifier for the person who
 * made it.
 */
final class ConsentLog
{
    private const SALT_FILE = '.salt';

    /**
     * @param array<string, mixed> $config the `log` config block
     */
    public function __construct(private readonly array $config)
    {
    }

    public function enabled(): bool
    {
        return ($this->config['backend'] ?? 'file') !== 'none';
    }

    /**
     * Append one decision. Returns the record id.
     *
     * @param array<string, mixed> $record
     */
    public function write(array $record): string
    {
        $id = (string)($record['id'] ?? self::newId());
        $record['id'] = $id;
        $record['created'] = (int)($record['created'] ?? time());

        if (!$this->enabled()) {
            return $id;
        }

        $path = $this->fileFor($record['created']);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create consent log directory: {$dir}");
        }

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return $id;
        }

        // LOCK_EX so concurrent visitors deciding at the same moment cannot
        // interleave half-lines into the file.
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

        $this->prune();

        return $id;
    }

    /**
     * Hash a value with the site salt.
     *
     * Returns null when hashing is off and the value is empty, so a record
     * never carries an empty-string hash that looks like real data.
     */
    public function hash(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return 'sha256:' . substr(hash_hmac('sha256', $value, $this->salt()), 0, 32);
    }

    /**
     * Read records newest-first.
     *
     * @param array{category?: string, method?: string, from?: int, to?: int} $filters
     * @return array{records: array<int, array<string, mixed>>, total: int}
     */
    public function query(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $matched = [];

        foreach ($this->files() as $file) {
            foreach ($this->readLines($file) as $record) {
                if ($this->matches($record, $filters)) {
                    $matched[] = $record;
                }
            }
        }

        usort($matched, static fn (array $a, array $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));

        $total = count($matched);
        $offset = max(0, ($page - 1) * $perPage);

        return [
            'records' => array_slice($matched, $offset, $perPage),
            'total' => $total,
        ];
    }

    /**
     * Acceptance metrics over a window, for the admin summary strip.
     *
     * @return array<string, mixed>
     */
    public function stats(int $days = 30): array
    {
        $since = time() - ($days * 86400);
        $total = 0;
        $methods = [];
        $categories = [];

        foreach ($this->files() as $file) {
            foreach ($this->readLines($file) as $record) {
                if ((int)($record['created'] ?? 0) < $since) {
                    continue;
                }
                $total++;
                $method = (string)($record['method'] ?? 'unknown');
                $methods[$method] = ($methods[$method] ?? 0) + 1;
                foreach ((array)($record['categories'] ?? []) as $category) {
                    $category = (string)$category;
                    $categories[$category] = ($categories[$category] ?? 0) + 1;
                }
            }
        }

        arsort($methods);

        $rates = [];
        foreach ($categories as $id => $count) {
            $rates[$id] = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
        }
        arsort($rates);

        $acceptedAll = $methods['accept_all'] ?? 0;

        return [
            'days' => $days,
            'total' => $total,
            'methods' => $methods,
            'category_counts' => $categories,
            'category_rates' => $rates,
            'accept_all_rate' => $total > 0 ? round(($acceptedAll / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Drop log files past the retention window.
     *
     * Called on every write. It costs one glob and a handful of integer
     * comparisons, which is cheaper than owning a scheduled task.
     */
    public function prune(): int
    {
        $days = (int)($this->config['retain_days'] ?? 400);
        if ($days <= 0) {
            return 0;
        }

        // Rotation is monthly, so a file is only removable once its whole
        // month is older than the window.
        $cutoff = strtotime("-{$days} days");
        $removed = 0;

        foreach ($this->files() as $file) {
            if (!preg_match('/(\d{4})-(\d{2})\.jsonl$/', $file, $m)) {
                continue;
            }
            $endOfMonth = (int)mktime(23, 59, 59, ((int)$m[2]) + 1, 0, (int)$m[1]);
            if ($endOfMonth < $cutoff && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Delete every record. Irreversible, and gated behind a destructive
     * confirm in the admin.
     */
    public function purge(): int
    {
        $removed = 0;
        foreach ($this->files() as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array<int, string> newest month first
     */
    public function files(): array
    {
        $files = glob($this->directory() . '/*.jsonl') ?: [];
        rsort($files);

        return $files;
    }

    public function directory(): string
    {
        $locator = Grav::instance()['locator'];
        $base = $locator->findResource('user://data', true) ?: (GRAV_ROOT . '/user/data');

        return rtrim((string)$base, '/') . '/consent';
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function fileFor(int $timestamp): string
    {
        return $this->directory() . '/' . date('Y-m', $timestamp) . '.jsonl';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readLines(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }

        $records = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }
        fclose($handle);

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $filters
     */
    private function matches(array $record, array $filters): bool
    {
        if (!empty($filters['category'])
            && !in_array((string)$filters['category'], (array)($record['categories'] ?? []), true)) {
            return false;
        }

        if (!empty($filters['method']) && (string)($record['method'] ?? '') !== (string)$filters['method']) {
            return false;
        }

        $created = (int)($record['created'] ?? 0);
        if (!empty($filters['from']) && $created < (int)$filters['from']) {
            return false;
        }

        if (!empty($filters['to']) && $created > (int)$filters['to']) {
            return false;
        }

        return true;
    }

    /**
     * A per-site secret, generated once.
     *
     * Kept beside the log rather than in `user/config/`, so exporting or
     * committing plugin settings never carries it along — a salt in a public
     * repository would make the IP hashes trivially reversible for anyone with
     * a list of candidate addresses.
     */
    private function salt(): string
    {
        $dir = $this->directory();
        $path = $dir . '/' . self::SALT_FILE;

        $existing = @file_get_contents($path);
        if (is_string($existing) && strlen(trim($existing)) >= 32) {
            return trim($existing);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $salt = bin2hex(random_bytes(32));
        @file_put_contents($path, $salt, LOCK_EX);
        @chmod($path, 0o600);

        return $salt;
    }
}
