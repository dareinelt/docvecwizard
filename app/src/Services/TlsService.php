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

    private function encryptionKey(): string
    {
        return hash('sha256', Config::string('SESSION_SECRET', ''), true);
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
        $config = $this->opensslConfig($keyType);
        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate private key: ' . $this->opensslError());
        }
        $dn = ['commonName' => $commonName];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256', 'config' => $this->csrConfig($san)]);
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
        $generated = $this->generateKeyAndCsr($commonName, $san, $keyType);
        $privateKey = $this->decryptPrivateKey($generated['private_key_enc']);
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Failed to load private key');
        }
        $altNames = $san === [] ? ['DNS:localhost', 'IP:127.0.0.1'] : $san;
        $csrConfig = $this->csrConfig($altNames);
        $cert = openssl_csr_sign($generated['csr_pem'], null, $key, $days, ['digest_alg' => 'sha256', 'config' => $csrConfig]);
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
            $csrKey = openssl_pkey_get_public((string) $row['csr_pem']);
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
        if (file_put_contents($this->store . '/cert.pem', $certPem) === false) {
            throw new \RuntimeException('Cannot write cert.pem to ' . $this->store);
        }
        if (file_put_contents($this->store . '/key.pem', $keyPem) === false) {
            throw new \RuntimeException('Cannot write key.pem to ' . $this->store);
        }
        @chmod($this->store . '/key.pem', 0600);
        Logger::channel('tls')->info('certificate written to store', ['store' => $this->store]);
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

    private function csrConfig(array $san): string
    {
        if ($san === []) {
            return dirname(__DIR__, 2) . '/config/openssl.cnf';
        }
        $config = "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\nprompt = no\n[dn]\n[v3_req]\nsubjectAltName = " . implode(',', $san) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'openssl');
        if ($tmp === false) {
            return dirname(__DIR__, 2) . '/config/openssl.cnf';
        }
        file_put_contents($tmp, $config);

        return $tmp;
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
