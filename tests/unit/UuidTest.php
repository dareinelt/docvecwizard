<?php

declare(strict_types=1);

use App\Core\Uuid;

test('Uuid::v4 produces RFC 4122 version-4 UUIDs', function (): void {
    $uuid = Uuid::v4();
    assert_eq(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid));
});

test('Uuid::v4 generates unique values', function (): void {
    $set = [];
    for ($i = 0; $i < 1000; $i++) {
        $set[Uuid::v4()] = true;
    }
    assert_count(1000, $set);
});
