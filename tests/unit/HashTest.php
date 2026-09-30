<?php

declare(strict_types=1);

test('SHA-256 hashing is deterministic for identical bytes', function (): void {
    $a = hash('sha256', "Das ist ein Testdokument.\nMit Umlauten äöüß.");
    $b = hash('sha256', "Das ist ein Testdokument.\nMit Umlauten äöüß.");
    assert_eq($a, $b);
    assert_eq(64, strlen($a));
});

test('SHA-256 differs for different content', function (): void {
    assert_true(hash('sha256', 'A') !== hash('sha256', 'B'));
});

test('hash_file matches hash() for the same bytes', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'docvec_hash_');
    file_put_contents($tmp, "Inhalt\nZeile 2\n");
    $expected = hash('sha256', "Inhalt\nZeile 2\n");
    assert_eq($expected, hash_file('sha256', $tmp));
    unlink($tmp);
});

test('duplicate detection key: two copies share one hash', function (): void {
    $content = "Deduplizierbarer Inhalt.";
    $tmp1 = tempnam(sys_get_temp_dir(), 'docvec_h1_');
    $tmp2 = tempnam(sys_get_temp_dir(), 'docvec_h2_');
    file_put_contents($tmp1, $content);
    file_put_contents($tmp2, $content);
    assert_eq(hash_file('sha256', $tmp1), hash_file('sha256', $tmp2));
    unlink($tmp1);
    unlink($tmp2);
});
