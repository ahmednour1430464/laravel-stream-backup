<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;

/**
 * Writes MySQL credentials to a restricted-permission temp file so they are
 * passed to mysqldump via --defaults-extra-file instead of the command line
 * (which would leak them in `ps aux`).
 *
 * Cleanup is registered as a shutdown function: unlinking immediately after
 * proc_open() is racy on some kernels/filesystems because mysqldump may not
 * have opened the file yet, and immediate unlinking also leaves the file on
 * disk if the PHP process dies between proc_open and unlink.
 */
final class MySQLCredentialFile
{
    private ?string $path = null;

    public function write(DatabaseCredentials $credentials): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stream_bkp_cnf_');

        if ($path === false) {
            throw new \RuntimeException('Unable to allocate a temp file for MySQL credentials.');
        }

        $contents = sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $credentials->host,
            $credentials->port,
            $credentials->username,
            $credentials->password,
        );

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        $this->path = $path;

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });

        return $path;
    }

    public function path(): ?string
    {
        return $this->path;
    }
}
