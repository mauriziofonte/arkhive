<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A 2.0 configuration file with a private password file in $this->dir (placeholders only).
 */
trait WritesConfig
{
    /**
     * @param array<string, string> $extra keys added or replaced
     */
    private function writeConfig(array $extra = []): string
    {
        file_put_contents("{$this->dir}/password", "repo-password-placeholder\n");
        chmod("{$this->dir}/password", 0600);
        $values = [
            'BACKUP_PATHS' => '/srv/www',
            'RESTIC_REPOSITORY' => '/srv/restic-repo',
            'RESTIC_PASSWORD_FILE' => "{$this->dir}/password",
            'RESTIC_HOST' => 'web1.example.com',
            ...$extra,
        ];
        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= "{$key}=\"{$value}\"\n";
        }
        file_put_contents("{$this->dir}/config", $lines);
        chmod("{$this->dir}/config", 0600);

        return "{$this->dir}/config";
    }

    /**
     * @return array<string, string>
     */
    private static function notify(string $on = 'always'): array
    {
        return ['NOTIFY' => 'true', 'NOTIFY_ON' => $on, 'SMTP_HOST' => 'smtp.example.com', 'SMTP_ENCRYPTION' => 'none', 'SMTP_FROM' => 'arkhive@example.com', 'SMTP_TO' => 'admin@example.com'];
    }
}
