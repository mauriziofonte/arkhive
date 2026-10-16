<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Configuration;

use Mfonte\Arkhive\Domain\Configuration\ConfigFileAccess;
use Mfonte\Arkhive\Domain\Configuration\SecretKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigFileAccessTest extends TestCase
{
    private const PATH = '/etc/arkhive/config';

    /**
     * @return iterable<string, array{int, int, int, list<string>, string|null, bool}>
     */
    public static function files(): iterable
    {
        $writable = 'The configuration /etc/arkhive/config is writable by group or others (mode %s): whoever can change it chooses the commands ArkHive runs';
        $readable = 'The configuration /etc/arkhive/config holds SMTP_PASSWORD and is readable by group or others (mode %s)';
        $foreign = 'The configuration /etc/arkhive/config belongs to uid 1000, not to the running user (uid 0) or root: that user can change it and so choose the commands ArkHive runs';

        // mode, owner, user, secrets => problem, refused
        yield 'private, with secrets' => [0600, 0, 0, ['SMTP_PASSWORD'], null, false];
        yield 'read-only for the owner' => [0400, 0, 0, ['SMTP_PASSWORD'], null, false];
        yield 'readable by others, no secrets' => [0644, 0, 0, [], null, false];
        yield 'readable by others, secrets' => [0644, 0, 0, ['SMTP_PASSWORD'], sprintf($readable, '0644'), false];
        yield 'readable by the group, secrets' => [0640, 0, 0, ['SMTP_PASSWORD'], sprintf($readable, '0640'), false];
        yield 'readable by others only, secrets' => [0604, 0, 0, ['SMTP_PASSWORD'], sprintf($readable, '0604'), false];
        yield 'writable by the group' => [0660, 0, 0, [], sprintf($writable, '0660'), true];
        yield 'writable by the group, read-only for the owner' => [0420, 0, 0, [], sprintf($writable, '0420'), true];
        yield 'writable by others' => [0602, 0, 0, ['SMTP_PASSWORD'], sprintf($writable, '0602'), true];
        yield 'world-writable' => [0666, 0, 0, [], sprintf($writable, '0666'), true];
        yield 'owned by another user' => [0600, 1000, 0, [], $foreign, true];
        yield 'owned by root, run by a user' => [0644, 0, 1000, [], null, false];
        yield 'owned by root, secrets readable by the group' => [0640, 0, 1000, ['SMTP_PASSWORD'], str_replace(['%s'], ['0640'], $readable), false];
    }

    /**
     * @param list<string> $secrets
     */
    #[DataProvider('files')]
    public function testWhoCanReadAndChangeIt(int $mode, int $owner, int $user, array $secrets, ?string $problem, bool $refused): void
    {
        $access = new ConfigFileAccess(self::PATH, $mode, $owner, $user, $secrets);

        self::assertSame($problem, $access->problem());
        self::assertSame($refused, $access->refused());
    }

    public function testReadableSecretsAreAWarningDuringTheGracePeriodAndRefusedWhenEnforced(): void
    {
        self::assertFalse(ConfigFileAccess::ENFORCE_PRIVATE, '2.1 only warns; flip it in a later minor version and say so in the CHANGELOG');

        $exposed = new ConfigFileAccess(self::PATH, 0644, 0, 0, ['MYSQL_PASSWORD'], enforcePrivate: true);
        self::assertTrue($exposed->refused());
        self::assertFalse((new ConfigFileAccess(self::PATH, 0644, 0, 0, [], enforcePrivate: true))->refused(), 'no secrets to protect');
        self::assertFalse((new ConfigFileAccess(self::PATH, 0600, 0, 0, ['MYSQL_PASSWORD'], enforcePrivate: true))->refused());
    }

    public function testTheHintFixesTheModeAndTheOwner(): void
    {
        self::assertSame('Run: chmod 600 /etc/arkhive/config', (new ConfigFileAccess(self::PATH, 0644, 0, 0, ['SMTP_PASSWORD']))->hint());
        self::assertSame('Run: chown 0 /etc/arkhive/config && chmod 600 /etc/arkhive/config', (new ConfigFileAccess(self::PATH, 0600, 1000, 0, []))->hint());
        self::assertSame('Run: chown 1000 /etc/arkhive/config && chmod 600 /etc/arkhive/config', (new ConfigFileAccess(self::PATH, 0640, 0, 1000, ['SMTP_PASSWORD']))->hint());
    }

    public function testSecretKeysAreTheNonEmptyPasswords(): void
    {
        self::assertSame(['MYSQL_PASSWORD', 'PGSQL_PASSWORD', 'SMTP_PASSWORD'], SecretKeys::KEYS);
        self::assertSame(['MYSQL_PASSWORD', 'SMTP_PASSWORD'], SecretKeys::found(['SMTP_PASSWORD' => 'x', 'PGSQL_PASSWORD' => '', 'MYSQL_PASSWORD' => 'y', 'SMTP_USER' => 'u']));
        self::assertSame([], SecretKeys::found(['RESTIC_PASSWORD_FILE' => '/etc/arkhive/password']));
    }
}
