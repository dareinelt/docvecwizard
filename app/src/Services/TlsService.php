<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Logger;

/**
 * TLS certificate workflow (concept adapted from lanpa):
 *   generate key (RSA/EC) -> private key encrypted at rest -> create CSR ->
 *   import CA-signed cert (public-key fingerprint matching, preview + confirm) ->
 *   activate -> write cert+key to the shared TLS store for nginx.
 * A self-signed fallback certificate is generated on first boot by the web
 * entrypoint and served until a valid certificate is activated.
 */
final class TlsService
{
    public const KEY_TYPES = ['rsa2048', 'rsa3072', 'rsa4096', 'ec256', 'ec384'];

    private string $store;

    public function __construct()
    {
        $this->store = rtrim(Config::string('TLS_STORE', '/srv/ssl'), '/');
    }

    public const MAX_PEM_BYTES = 65536;
    public const MAX_SAN_ENTRIES = 50;

    /**
     * SECURITY FIX: previously derived from SESSION_SECRET without any
     * strength check (an empty secret produced a well-known key). Now refuses
     * to operate with a missing/placeholder/short secret.
     */
    private function encryptionKey(): string
    {
        return hash('sha256', Config::secret('SESSION_SECRET'), true);
    }

    /**
     * SECURITY FIX: Common name and SAN entries were written verbatim into an
     * OpenSSL config file. A newline in a SAN value allowed injecting arbitrary
     * config directives (e.g. ".include /etc/..."). Values are now validated
     * against strict allow-lists before they reach OpenSSL.
     *
     * @param array<mixed> $san
     * @return array{0:string,1:list<string>}
     */
    public static function validateSubject(string $commonName, array $san): array
    {
        $commonName = trim($commonName);
        if ($commonName === '' || strlen($commonName) > 64 || preg_match('/^[A-Za-z0-9*][A-Za-z0-9*._-]*$/D', $commonName) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Common Name (erlaubt: Hostname oder IP-Adresse, max. 64 Zeichen).');
        }
        if (count($san) > self::MAX_SAN_ENTRIES) {
            throw new \InvalidArgumentException(sprintf('Maximal %d SAN-Einträge erlaubt.', self::MAX_SAN_ENTRIES));
        }
        $clean = [];
        foreach ($san as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('SAN-Einträge müssen Zeichenketten sein.');
            }
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^DNS:([A-Za-z0-9*][A-Za-z0-9*.-]{0,252})$/D', $entry, $m) === 1) {
                $clean[] = 'DNS:' . $m[1];
                continue;
            }
            if (preg_match('/^IP:(.+)$/D', $entry, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
                $clean[] = 'IP:' . $m[1];
                continue;
            }
            throw new \InvalidArgumentException('Ungültiger SAN-Eintrag (Format "DNS:host.example" oder "IP:192.0.2.1").');
        }

        return [$commonName, array_values(array_unique($clean))];
    }

    public function encryptPrivateKey(string $pem): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($pem, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decryptPrivateKey(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Invalid encrypted key blob');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt private key');
        }

        return $plain;
    }

    /** @return array{key_type:string,private_key_enc:string,csr_pem:string} */
    public function generateKeyAndCsr(string $commonName, array $san = [], string $keyType = 'rsa3072'): array
    {
        if (!in_array($keyType, self::KEY_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported key type: ' . $keyType);
        }
        [$commonName, $san] = self::validateSubject($commonName, $san);
        $config = $this->opensslConfig($keyType);
        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate private key: ' . $this->opensslError());
        }
        $dn = ['commonName' => $commonName];
        if ($san === []) {
            // FIX: the bundled default config added "localhost" as SAN to every CSR.
            $san = [(filter_var($commonName, FILTER_VALIDATE_IP) !== false ? 'IP:' : 'DNS:') . $commonName];
        }
        $csr = $this->withCsrConfig($san, fn (string $cnf) => openssl_csr_new($dn, $key, [
            'digest_alg' => 'sha256',
            'config' => $cnf,
            'req_extensions' => 'v3_req',
        ]));
        if ($csr === false) {
            throw new \RuntimeException('Failed to generate CSR: ' . $this->opensslError());
        }
        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($key, $keyOut);

        return [
            'key_type' => $keyType,
            'private_key_enc' => $this->encryptPrivateKey($keyOut),
            'csr_pem' => $csrOut,
        ];
    }

    /** @return array<string,mixed> */
    public function createCsr(string $commonName, array $san = [], string $keyType = 'rsa3072'): array
    {
        [$commonName, $san] = self::validateSubject($commonName, $san);
        $generated = $this->generateKeyAndCsr($commonName, $san, $keyType);
        Db::execute(
            'INSERT INTO tls_certificates (kind, common_name, san, key_type, private_key_enc, csr_pem)
             VALUES ("csr", ?, ?, ?, ?, ?)',
            [
                $commonName,
                $san === [] ? null : json_encode($san, JSON_UNESCAPED_SLASHES),
                $keyType,
                $generated['private_key_enc'],
                $generated['csr_pem'],
            ]
        );
        $id = Db::insertId();
        Audit::record('tls.csr.created', 'tls_certificate', (string) $id);

        return $this->getById($id) ?? ['id' => $id, 'csr_pem' => $generated['csr_pem']];
    }

    /** @return array<string,mixed> */
    public function generateSelfSigned(string $commonName, array $san = [], string $keyType = 'rsa3072', int $days = 825): array
    {
        [$commonName, $san] = self::validateSubject($commonName, $san);
        $generated = $this->generateKeyAndCsr($commonName, $san, $keyType);
        $privateKey = $this->decryptPrivateKey($generated['private_key_enc']);
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Failed to load private key');
        }
        $altNames = $san === [] ? ['DNS:localhost', 'IP:127.0.0.1'] : $san;
        // FIX: x509_extensions is now set so the SAN actually ends up in the
        // self-signed certificate (browsers ignore the CN).
        $cert = $this->withCsrConfig($altNames, fn (string $cnf) => openssl_csr_sign($generated['csr_pem'], null, $key, $days, [
            'digest_alg' => 'sha256',
            'config' => $cnf,
            'x509_extensions' => 'v3_req',
        ]));
        if ($cert === false) {
            throw new \RuntimeException('Failed to sign certificate: ' . $this->opensslError());
        }
        openssl_x509_export($cert, $certOut);
        $parsed = openssl_x509_parse($certOut);
        $fingerprint = openssl_x509_fingerprint($certOut, 'sha256');

        Db::execute(
            'INSERT INTO tls_certificates (kind, common_name, san, subject, issuer, not_before, not_after, fingerprint, key_type, private_key_enc, csr_pem, cert_pem, active)
             VALUES ("selfsigned", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
            [
                $commonName,
                json_encode($altNames, JSON_UNESCAPED_SLASHES),
                json_encode($parsed['subject'] ?? [], JSON_UNESCAPED_SLASHES),
                json_encode($parsed['issuer'] ?? [], JSON_UNESCAPED_SLASHES),
                isset($parsed['validFrom_time_t']) ? gmdate('Y-m-d H:i:s', $parsed['validFrom_time_t']) : null,
                isset($parsed['validTo_time_t']) ? gmdate('Y-m-d H:i:s', $parsed['validTo_time_t']) : null,
                (string) $fingerprint,
                $keyType,
                $generated['private_key_enc'],
                $generated['csr_pem'],
                $certOut,
            ]
        );
        $id = Db::insertId();
        $this->activate($id);
        Audit::record('tls.selfsigned.generated', 'tls_certificate', (string) $id);

        return $this->getById($id) ?? ['id' => $id];
    }

    /**
     * Import a CA-signed certificate, matching it against the stored CSR by
     * public-key fingerprint. Returns a preview; caller confirms activation.
     *
     * @return array<string,mixed>
     */
    public function importCert(string $certPem): array
    {
        $certPem = trim($certPem) . "\n";
        if (strlen($certPem) > self::MAX_PEM_BYTES || !str_contains($certPem, '-----BEGIN CERTIFICATE-----')) {
            throw new \InvalidArgumentException('Invalid certificate PEM');
        }
        if (str_contains($certPem, 'PRIVATE KEY')) {
            // Never accept (and store in plain text) a private key pasted by mistake.
            throw new \InvalidArgumentException('The PEM must not contain a private key');
        }
        $certParsed = openssl_x509_parse($certPem);
        if ($certParsed === false) {
            throw new \InvalidArgumentException('Invalid certificate PEM');
        }
        $certFingerprint = (string) openssl_x509_fingerprint($certPem, 'sha256');
        $certKey = openssl_pkey_get_public($certPem);

        // Find matching CSR by public-key fingerprint.
        $csrs = Db::fetchAll('SELECT * FROM tls_certificates WHERE kind = "csr" AND cert_pem IS NULL ORDER BY id DESC');
        $matched = null;
        foreach ($csrs as $row) {
            $csrKey = openssl_csr_get_public_key((string) $row['csr_pem']);
            if ($csrKey === false || $certKey === false) {
                continue;
            }
            $csrDetails = openssl_pkey_get_details($csrKey);
            $certDetails = openssl_pkey_get_details($certKey);
            if (($csrDetails['key'] ?? '') === ($certDetails['key'] ?? '')) {
                $matched = $row;
                break;
            }
        }
        if ($matched === null) {
            throw new \InvalidArgumentException('Certificate does not match any stored CSR');
        }
        $parsedSubject = json_encode($certParsed['subject'] ?? [], JSON_UNESCAPED_SLASHES);
        $parsedIssuer = json_encode($certParsed['issuer'] ?? [], JSON_UNESCAPED_SLASHES);

        Db::execute(
            'UPDATE tls_certificates SET cert_pem = ?, subject = ?, issuer = ?, not_before = ?, not_after = ?, fingerprint = ?, kind = "imported" WHERE id = ?',
            [
                $certPem,
                $parsedSubject,
                $parsedIssuer,
                isset($certParsed['validFrom_time_t']) ? gmdate('Y-m-d H:i:s', $certParsed['validFrom_time_t']) : null,
                isset($certParsed['validTo_time_t']) ? gmdate('Y-m-d H:i:s', $certParsed['validTo_time_t']) : null,
                $certFingerprint,
                (int) $matched['id'],
            ]
        );
        Audit::record('tls.cert.imported', 'tls_certificate', (string) $matched['id']);

        return $this->getById((int) $matched['id']) ?? [];
    }

    public function activate(int $id): void
    {
        $row = Db::fetchOne('SELECT * FROM tls_certificates WHERE id = ?', [$id]);
        if ($row === null || empty($row['cert_pem']) || empty($row['private_key_enc'])) {
            throw new \InvalidArgumentException('Certificate is not complete (missing cert or key)');
        }
        $keyPem = $this->decryptPrivateKey((string) $row['private_key_enc']);
        $this->writeStore((string) $row['cert_pem'], $keyPem);
        Db::transaction(function () use ($id): void {
            Db::execute('UPDATE tls_certificates SET active = 0');
            Db::execute('UPDATE tls_certificates SET active = 1 WHERE id = ?', [$id]);
        });
        Audit::record('tls.cert.activated', 'tls_certificate', (string) $id);
    }

    private function writeStore(string $certPem, string $keyPem): void
    {
        if (!is_dir($this->store)) {
            @mkdir($this->store, 0700, true);
        }
        // FIX: files are written to temp files and atomically renamed. Before,
        // cert.pem and key.pem were overwritten in place (nginx could reload a
        // mismatching cert/key pair, the key was briefly world-readable, and
        // overwriting the root-owned fallback files failed).
        // The key is replaced first; nginx reloads when cert.pem changes.
        $this->atomicWrite($this->store . '/key.pem', $keyPem, 0600);
        $this->atomicWrite($this->store . '/cert.pem', $certPem, 0644);
        Logger::channel('tls')->info('certificate written to store', ['store' => $this->store]);
    }

    private function atomicWrite(string $target, string $content, int $mode): void
    {
        $tmp = tempnam($this->store, '.tls-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temporary file in ' . $this->store);
        }
        try {
            // tempnam() creates the file with mode 0600, so the key is never exposed.
            if (file_put_contents($tmp, $content) === false || !chmod($tmp, $mode) || !rename($tmp, $target)) {
                throw new \RuntimeException('Cannot write ' . basename($target) . ' to ' . $this->store);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $served = null;
        $certPath = $this->store . '/cert.pem';
        if (is_file($certPath)) {
            $pem = (string) file_get_contents($certPath);
            $parsed = openssl_x509_parse($pem);
            if ($parsed !== false) {
                $served = [
                    'subject' => $parsed['subject'] ?? [],
                    'issuer' => $parsed['issuer'] ?? [],
                    'valid_from' => isset($parsed['validFrom_time_t']) ? gmdate('Y-m-d\TH:i:s\Z', $parsed['validFrom_time_t']) : null,
                    'valid_to' => isset($parsed['validTo_time_t']) ? gmdate('Y-m-d\TH:i:s\Z', $parsed['validTo_time_t']) : null,
                    'fingerprint' => (string) openssl_x509_fingerprint($pem, 'sha256'),
                    'self_signed' => ($parsed['subject'] ?? []) === ($parsed['issuer'] ?? []),
                ];
            }
        }

        return [
            'served' => $served,
            'records' => $this->records(),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function records(): array
    {
        return Db::fetchAll(
            'SELECT id, kind, common_name, san, subject, issuer, not_before, not_after, fingerprint, key_type, active, created_at, updated_at,
                    (csr_pem IS NOT NULL) AS has_csr, (cert_pem IS NOT NULL) AS has_cert
             FROM tls_certificates ORDER BY id DESC LIMIT 50'
        );
    }

    /** @return array<string,mixed>|null */
    public function getById(int $id): ?array
    {
        $row = Db::fetchOne(
            'SELECT id, kind, common_name, san, subject, issuer, not_before, not_after, fingerprint, key_type, active, created_at, updated_at,
                    csr_pem, cert_pem
             FROM tls_certificates WHERE id = ?',
            [$id]
        );
        if ($row !== null) {
            $row['san'] = $row['san'] !== null ? json_decode((string) $row['san'], true) : [];
            $row['subject'] = $row['subject'] !== null ? json_decode((string) $row['subject'], true) : [];
            $row['issuer'] = $row['issuer'] !== null ? json_decode((string) $row['issuer'], true) : [];
            $row['private_key_enc'] = null;
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function opensslConfig(string $keyType): array
    {
        return match ($keyType) {
            'ec256' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            'ec384' => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'],
            'rsa4096' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096],
            'rsa2048' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            default => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072],
        };
    }

    /**
     * Run an OpenSSL operation with a temporary config carrying the SAN list.
     * FIX: the temp file was previously never deleted (one leaked file per
     * request); values are pre-validated by validateSubject().
     *
     * @template T
     * @param list<string> $san
     * @param callable(string):T $operation
     * @return T
     */
    private function withCsrConfig(array $san, callable $operation): mixed
    {
        if ($san === []) {
            return $operation(dirname(__DIR__, 2) . '/config/openssl.cnf');
        }
        foreach ($san as $entry) {
            if (preg_match('/[\r\n,\\\\]/', $entry) === 1) {
                throw new \InvalidArgumentException('Invalid SAN entry');
            }
        }
        $config = "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\nprompt = no\n[dn]\n[v3_req]\nsubjectAltName = " . implode(',', $san) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'openssl');
        if ($tmp === false || file_put_contents($tmp, $config) === false) {
            throw new \RuntimeException('Cannot write temporary OpenSSL config');
        }
        try {
            return $operation($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function opensslError(): string
    {
        $errors = [];
        while (($msg = openssl_error_string()) !== false) {
            $errors[] = $msg;
        }

        return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
    }
}
