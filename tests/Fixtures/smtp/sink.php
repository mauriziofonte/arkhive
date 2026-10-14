<?php

declare(strict_types=1);

/*
 * A minimal SMTP server for tests: plain SMTP on 127.0.0.1 (no STARTTLS), AUTH PLAIN and LOGIN, one
 * JSON file per accepted message (credentials, envelope, data) in <out-dir>. The port it listens on is
 * written to <port-file>. With "reject-auth" every AUTH fails with 535. Runs until killed.
 *
 * usage: php sink.php <port-file> <out-dir> [reject-auth]
 */

[, $portFile, $outDir] = $argv;
$rejectAuth = ($argv[3] ?? '') === 'reject-auth';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents("{$portFile}.tmp", substr($name, strrpos($name, ':') + 1));
rename("{$portFile}.tmp", $portFile);

$count = 0;
while (($client = @stream_socket_accept($server, -1)) !== false) {
    $say = static function (string $line) use ($client): void {
        fwrite($client, $line . "\r\n");
    };
    $read = static function () use ($client): ?string {
        $line = fgets($client);

        return $line === false ? null : rtrim($line, "\r\n");
    };
    $message = ['auth' => null, 'from' => null, 'to' => [], 'data' => ''];
    $say('220 sink.example.com ESMTP test sink');
    while (($line = $read()) !== null) {
        $verb = strtoupper(strtok($line, ' ') ?: '');
        $argument = trim(substr($line, strlen($verb)));
        switch ($verb) {
            case 'EHLO':
                $say('250-sink.example.com');
                $say('250-AUTH PLAIN LOGIN');
                $say('250 8BITMIME');
                break;
            case 'HELO':
            case 'RSET':
            case 'NOOP':
                $say('250 OK');
                break;
            case 'AUTH':
                [$mechanism, $initial] = array_pad(explode(' ', $argument, 2), 2, null);
                if (strtoupper((string) $mechanism) === 'PLAIN') {
                    if ($initial === null) {
                        $say('334 ');
                        $initial = $read();
                    }
                    $parts = explode("\0", (string) base64_decode((string) $initial, true));
                    $credentials = ['user' => $parts[1] ?? '', 'password' => $parts[2] ?? ''];
                } else {
                    $say('334 VXNlcm5hbWU6');
                    $user = (string) base64_decode((string) $read(), true);
                    $say('334 UGFzc3dvcmQ6');
                    $credentials = ['user' => $user, 'password' => (string) base64_decode((string) $read(), true)];
                }
                if ($rejectAuth) {
                    $say('535 5.7.8 Authentication credentials invalid');
                } else {
                    $message['auth'] = $credentials;
                    $say('235 2.7.0 Authentication successful');
                }
                break;
            case 'MAIL':
                $message['from'] = $argument;
                $say('250 OK');
                break;
            case 'RCPT':
                $message['to'][] = $argument;
                $say('250 OK');
                break;
            case 'DATA':
                $say('354 End data with <CR><LF>.<CR><LF>');
                $data = '';
                while (($dataLine = $read()) !== null && $dataLine !== '.') {
                    $data .= (str_starts_with($dataLine, '..') ? substr($dataLine, 1) : $dataLine) . "\n";
                }
                $message['data'] = $data;
                file_put_contents(sprintf('%s/%03d.json', $outDir, ++$count), json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $message = ['auth' => $message['auth'], 'from' => null, 'to' => [], 'data' => ''];
                $say('250 OK queued');
                break;
            case 'QUIT':
                $say('221 Bye');
                break 2;
            default:
                $say('502 Command not implemented');
        }
    }
    fclose($client);
}
