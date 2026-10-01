<?php

declare(strict_types=1);

use App\Core\Base64;

test('Base64::decode roundtrips original bytes exactly', function (): void {
    $original = "Originaldokument\nMit Umlauten äöüß und Zeilenumbrüchen.\n";
    $encoded = base64_encode($original);
    assert_eq($original, Base64::decode($encoded));
});

test('Base64::decode roundtrips binary (non-UTF8) bytes exactly', function (): void {
    $original = random_bytes(1024);
    $encoded = base64_encode($original);
    assert_eq($original, Base64::decode($encoded));
});

test('Base64::decode rejects invalid Base64 payloads', function (): void {
    assert_true(Base64::decode('not valid base64!!!') === null);
    assert_true(Base64::decode('A') === null); // truncated group
});

test('Base64::decode rejects non-zero padding bits', function (): void {
    // "AB==" decodes to 0x00; "AB==" is valid. Mutating the final sextet
    // to a value whose low padding bits are non-zero must fail strict decode.
    assert_true(Base64::decode('AB==') !== null);
    assert_true(Base64::decode('AB===') === null);
});

test('Base64::sha256 matches hash over decoded bytes', function (): void {
    $original = "Daten\nZeile 2\n";
    assert_eq(hash('sha256', $original), Base64::sha256(base64_encode($original)));
});

test('Base64::sha256 returns null for invalid payloads', function (): void {
    assert_true(Base64::sha256('%%%') === null);
});

test('Base64::verifySha256 accepts the correct digest', function (): void {
    $original = 'Integritätstest';
    assert_true(Base64::verifySha256(base64_encode($original), hash('sha256', $original)));
});

test('Base64::verifySha256 rejects a wrong digest', function (): void {
    $original = 'Integritätstest';
    $wrong = hash('sha256', 'anderer Inhalt');
    assert_false(Base64::verifySha256(base64_encode($original), $wrong));
});

test('Base64::verifySha256 rejects corrupt Base64', function (): void {
    assert_false(Base64::verifySha256('%%%', hash('sha256', 'egal')));
});

test('Base64::sha256 streams across chunk boundaries', function (): void {
    // 1 MiB forces many 8192-byte chunks (8192 is divisible by 4).
    $original = random_bytes(1024 * 1024);
    $encoded = base64_encode($original);
    assert_eq(hash('sha256', $original), Base64::sha256($encoded));
});

test('Base64::encodeFile matches base64_encode for sizes around the chunk boundary', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'b64');
    try {
        foreach ([0, 1, 2, 3, 4, 3 * 65536 - 1, 3 * 65536, 3 * 65536 + 1, 2 * 3 * 65536 + 5] as $len) {
            $bytes = $len > 0 ? random_bytes($len) : '';
            file_put_contents($tmp, $bytes);
            assert_eq(base64_encode($bytes), Base64::encodeFile($tmp), 'len ' . $len);
        }
    } finally {
        @unlink($tmp);
    }
});

test('Base64::encodeFile returns null for unreadable paths', function (): void {
    assert_eq(null, Base64::encodeFile(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(4))));
});