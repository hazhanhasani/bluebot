<?php

declare(strict_types=1);

require_once __DIR__ . '/support/source_function.php';
loadTestSourceFunction(dirname(__DIR__) . '/api/client.php', 'clientBody');
loadTestSourceFunction(dirname(__DIR__) . '/function.php', 'sanitize_recursive');
require_once dirname(__DIR__) . '/src/Services/AppClientAuth.php';

final class ClientBodyError extends RuntimeException {}

function clientResponse(bool $ok, string $message, array $data = [], int $status = 200): never
{
    throw new ClientBodyError($message, $status);
}

final class ClientBodyInput
{
    public mixed $context;
    private string $body = '';
    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($path !== 'php://input') {
            throw new RuntimeException('Unexpected input stream.');
        }
        $this->body = $GLOBALS['requestBody'];
        return true;
    }

    public function stream_read(int $count): string
    {
        $data = substr($this->body, $this->offset, $count);
        $this->offset += strlen($data);
        return $data;
    }

    public function stream_eof(): bool { return $this->offset >= strlen($this->body); }
    public function stream_stat(): array { return []; }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fingerprints = new ReflectionMethod(AppClientAuth::class, 'qrFingerprints');
stream_wrapper_unregister('php');
stream_wrapper_register('php', ClientBodyInput::class);

try {
    foreach ([
        'vless://test@vpn.example.com:443?security=tls&type=ws&path=%2F#France',
        'trojan://p%26ss@vpn.example.com:443?security=tls&type=tcp#Test',
        'https://vpn.example.com/sub/test?token=one&format=base64',
    ] as $payload) {
        $requestBody = json_encode(['action' => 'qr-login', 'qr_payload' => $payload], JSON_THROW_ON_ERROR);
        $body = clientBody();
        $assert($body['qr_payload'] === $payload, 'Scanned QR payload changed during JSON decoding.');
        $actual = $fingerprints->invoke(null, $body['qr_payload']);
        $assert(in_array(hash('sha256', $payload), $actual, true), 'Scanned QR no longer matches its registered service fingerprint.');
    }

    $password = ' p&ss\'"<word> ';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $requestBody = json_encode(['action' => 'login', 'password' => $password], JSON_THROW_ON_ERROR);
    $assert(password_verify(clientBody()['password'], $hash), 'JSON request changed the password before verification.');

    $requestBody = '   ';
    $assert(clientBody() === [], 'Empty request bodies must retain their established behavior.');
    foreach (['{invalid', 'null', '"not an object"'] as $invalid) {
        $requestBody = $invalid;
        try {
            clientBody();
            throw new RuntimeException('Invalid JSON body was accepted.');
        } catch (ClientBodyError $e) {
            $assert($e->getCode() === 400, 'Invalid JSON bodies must return HTTP 400.');
        }
    }
} finally {
    stream_wrapper_restore('php');
}

echo "Android client request body tests OK.\n";
