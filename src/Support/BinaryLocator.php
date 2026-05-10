<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Exceptions\BinaryNotFoundException;

/**
 * Resolves an external binary (mysqldump, pigz, gzip) across common install
 * layouts: explicit config path -> PATH lookup via `which` -> hard-coded
 * fallbacks for Homebrew, Linux distro packages, and cPanel.
 */
final class BinaryLocator
{
    /**
     * @var array<string, string> cache of resolved absolute paths
     */
    private array $cache = [];

    /**
     * @param array<string, string|null> $configured map of binary name to configured absolute path
     */
    public function __construct(private readonly array $configured = [])
    {
    }

    public function locate(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $configured = $this->configured[$name] ?? null;
        if (is_string($configured) && $configured !== '' && $configured !== $name && is_file($configured) && is_executable($configured)) {
            return $this->cache[$name] = $configured;
        }

        $whichPath = $this->whichLookup($name);
        if ($whichPath !== null) {
            return $this->cache[$name] = $whichPath;
        }

        foreach ($this->candidates($name) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $this->cache[$name] = $candidate;
            }
        }

        throw new BinaryNotFoundException(sprintf(
            "Cannot locate '%s'. Install it or set the explicit binary path in your stream-backup config.",
            $name,
        ));
    }

    private function whichLookup(string $name): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open(['which', $name], $descriptors, $pipes);
        if (! is_resource($proc)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $exit = proc_close($proc);

        if ($exit !== 0) {
            return null;
        }

        $path = trim($stdout);
        return $path !== '' ? $path : null;
    }

    /**
     * @return array<int, string>
     */
    private function candidates(string $name): array
    {
        return [
            "/usr/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/opt/homebrew/bin/{$name}",
            "/opt/homebrew/opt/mysql-client/bin/{$name}",
            "/usr/local/mysql/bin/{$name}",
        ];
    }
}
