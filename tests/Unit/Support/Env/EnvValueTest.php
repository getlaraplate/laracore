<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Modules\Core\Support\Env\EnvValue;

it('writes a plain value bare', function (string $value): void {
    expect(EnvValue::format($value))->toBe($value);
})->with(['abc', 'http://localhost:8080', '1.5', 'a-b_c', 'user@host', '10,20', '']);

it('round-trips through Dotenv::parse', function (string $value): void {
    $parsed = Dotenv::parse('NAME=' . EnvValue::format($value));

    expect($parsed['NAME'])->toBe($value);
})->with([
    'empty' => '',
    'space' => 'two words',
    'leading and trailing space' => '  padded  ',
    'hash' => '#comment',
    'inner hash' => 'a#b',
    'double quote' => 'say "hi"',
    'single quote' => "it's",
    'dollar' => '$HOME',
    'braced variable' => '${X}',
    'backslash' => 'C:\\temp\\file',
    'equals' => 'a=b',
    'backtick' => 'a`b',
    'unicode' => 'è日本',
    'line break' => "line1\nline2",
    'crlf' => "line1\r\nline2",
    'carriage return' => "a\rb",
    'mixed' => "a \"b\" 'c' \$d \\e #f",
]);

it('quotes what Dotenv would read differently', function (string $value): void {
    expect(EnvValue::format($value))->toStartWith('"')->toEndWith('"');
})->with(['two words', '#x', 'say "hi"', '$HOME', "a\nb"]);

it('detects secret names', function (string $name, bool $secret): void {
    expect(EnvValue::isSecretName($name))->toBe($secret);
})->with([
    ['API_KEY', true],
    ['DB_PASSWORD', true],
    ['GITHUB_CLIENT_SECRET', true],
    ['ACCESS_TOKEN', true],
    ['SOME_PASS', true],
    ['X_CREDENTIAL', true],
    ['AWS_ACCESS_KEY_ID', true],
    ['SECRET_KEY_BASE', true],
    ['DB_PASSWORD_FILE', true],
    ['TOKEN', true],
    ['APP_NAME', false],
    ['KEYBOARD_LAYOUT', false],
    ['PASSIVE_MODE', false],
    ['MONKEY_BUSINESS', false],
]);
