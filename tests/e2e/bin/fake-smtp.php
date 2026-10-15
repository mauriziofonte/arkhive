<?php

declare(strict_types=1);

/*
 * A minimal SMTP server for the end-to-end suite: listens on 127.0.0.1 (a free port, printed on
 * stdout), answers each message after DATA with the next code of the list given (250 once the list
 * is used up), and saves every accepted message to the spool directory.
 *
 * Usage: php fake-smtp.php <spool directory> [<code>,<code>,...]
 */

$spool = $argv[1] ?? null;
if ($spool === null || !is_dir($spool)) {
    fwrite(STDERR, "usage: fake-smtp.php <spool directory> [codes]\n");
    exit(64);
}
$codes = ($argv[2] ?? '') === '' ? [] : array_map('intval', explode(',', $argv[2]));

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(71);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1) . "\n");
fflush(STDOUT);

$received = 0;
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    $say = static function (string $line) use ($client): void {
        fwrite($client, $line . "\r\n");
    };
    $say('220 smtp.example.com ESMTP fake');
    $data = null;
    while (($line = fgets($client)) !== false) {
        if ($data !== null) {
            if (rtrim($line, "\r\n") !== '.') {
                $data .= $line;

                continue;
            }
            $code = array_shift($codes) ?? 250;
            if ($code === 250) {
                file_put_contents(sprintf('%s/%03d.eml', $spool, ++$received), $data);
                $say('250 2.0.0 queued');
            } elseif ($code >= 400 && $code < 500) {
                $say("{$code} 4.7.1 Greylisted, try again later");
            } else {
                $say("{$code} 5.7.1 Message rejected");
            }
            $data = null;

            continue;
        }
        $command = strtoupper(substr(trim($line), 0, 4));
        match ($command) {
            'EHLO' => $say("250-smtp.example.com\r\n250 8BITMIME"),
            'HELO', 'MAIL', 'RCPT', 'RSET', 'NOOP' => $say('250 OK'),
            'DATA' => (static function () use ($say, &$data): void {
                $data = '';
                $say('354 End data with <CR><LF>.<CR><LF>');
            })(),
            'QUIT' => $say('221 Bye'),
            default => $say('502 Command not implemented'),
        };
        if ($command === 'QUIT') {
            break;
        }
    }
    fclose($client);
}
