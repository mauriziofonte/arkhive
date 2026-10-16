<?php

declare(strict_types=1);

namespace Mfonte\Arkhive\Domain\Configuration;

/**
 * Who can read and change the configuration file. Whoever can change it chooses the commands ArkHive
 * runs (RESTIC_BINARY, RESTIC_SFTP_COMMAND) and where the data goes; whoever can read it reads the
 * database and SMTP passwords it holds (SecretKeys). The rule for changing it is sshd's StrictModes:
 * owned by the running user or by root, and not writable by group or others.
 */
final readonly class ConfigFileAccess
{
    /**
     * 2.1 grace period: a configuration that holds secrets but is readable by group or others is a
     * warning, so that existing installs keep running; a later minor version sets this to true and
     * refuses it with exit 77. A configuration that someone else can change is always refused.
     */
    public const ENFORCE_PRIVATE = false;

    /**
     * @param int          $mode    permission bits (st_mode & 07777)
     * @param int          $owner   the file's uid
     * @param int          $user    the effective uid ArkHive runs as
     * @param list<string> $secrets the secret keys set in the file (SecretKeys::found())
     * @param bool         $enforcePrivate refuse readable secrets too (ENFORCE_PRIVATE; tests)
     */
    public function __construct(
        public string $path,
        public int $mode,
        public int $owner,
        public int $user,
        public array $secrets,
        public bool $enforcePrivate = self::ENFORCE_PRIVATE,
    ) {
    }

    /**
     * Someone besides the running user and root can change the file: group or others may write
     * it, or it belongs to another user (who can chmod it and write it).
     */
    public function changeableByOthers(): bool
    {
        return ($this->mode & 0022) !== 0 || ($this->owner !== $this->user && $this->owner !== 0);
    }

    public function readableByOthers(): bool
    {
        return ($this->mode & 0044) !== 0;
    }

    public function private(): bool
    {
        return ($this->mode & 0077) === 0 && $this->owner === $this->user;
    }

    /**
     * Secrets that someone besides the running user can read.
     */
    public function exposesSecrets(): bool
    {
        return $this->secrets !== [] && !$this->private();
    }

    /**
     * What is wrong with the file, most serious first; null when nothing is.
     */
    public function problem(): ?string
    {
        $mode = sprintf('%04o', $this->mode);
        if (($this->mode & 0022) !== 0) {
            return "The configuration {$this->path} is writable by group or others (mode {$mode}): whoever can change it chooses the commands ArkHive runs";
        }
        if ($this->changeableByOthers()) {
            return "The configuration {$this->path} belongs to uid {$this->owner}, not to the running user (uid {$this->user}) or root: that user can change it and so choose the commands ArkHive runs";
        }
        if (!$this->exposesSecrets()) {
            return null;
        }

        return "The configuration {$this->path} holds " . implode(', ', $this->secrets) . " and is readable by group or others (mode {$mode})";
    }

    public function hint(): string
    {
        $fix = $this->owner === $this->user ? "chmod 600 {$this->path}" : "chown {$this->user} {$this->path} && chmod 600 {$this->path}";

        return "Run: {$fix}";
    }

    /**
     * The problem refuses the run (exit 77) rather than being a warning.
     */
    public function refused(): bool
    {
        return $this->changeableByOthers() || ($this->enforcePrivate && $this->exposesSecrets());
    }
}
