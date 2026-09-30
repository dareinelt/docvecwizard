<?php

declare(strict_types=1);

use App\Core\Config;

test('Config::string returns set values and defaults', function (): void {
    Config::load(['A' => '1', 'B' => 'hello']);
    assert_eq('1', Config::string('A'));
    assert_eq('hello', Config::string('B'));
    assert_eq('fallback', Config::string('MISSING', 'fallback'));
    assert_eq('', Config::string('MISSING'));
});

test('Config::int casts strings and returns defaults', function (): void {
    Config::load(['N' => '42', 'EMPTY' => '']);
    assert_eq(42, Config::int('N'));
    assert_eq(7, Config::int('EMPTY', 7));
    assert_eq(0, Config::int('MISSING'));
});

test('Config::bool parses common truthy/falsy forms', function (): void {
    Config::load(['T1' => 'true', 'T2' => '1', 'F1' => 'false', 'F2' => '0', 'F3' => '']);
    assert_true(Config::bool('T1'));
    assert_true(Config::bool('T2'));
    assert_false(Config::bool('F1'));
    assert_false(Config::bool('F2'));
    assert_false(Config::bool('F3'));
    assert_false(Config::bool('MISSING'));
});

test('Config::set overrides a previously loaded value', function (): void {
    Config::load(['K' => 'before']);
    Config::set('K', 'after');
    assert_eq('after', Config::string('K'));
});

test('Config::load ignores non-string values', function (): void {
    Config::load(['S' => 'ok', 'ARR' => ['x']]);
    assert_eq('ok', Config::string('S'));
    assert_eq('', Config::string('ARR'));
});
