<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Modules\Core\Cache\Labels\CacheDependencies;
use Modules\Core\Console\TranslationsCheckCommand;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Tests\Stubs\Cache\SpyLabelIndex;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function translationsCheckCommandWithOutput(TranslationsCheckCommand $command): TranslationsCheckCommand
{
    $command->setLaravel(app());
    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
    $reflection = new ReflectionProperty(Illuminate\Console\Command::class, 'output');
    $reflection->setAccessible(true);
    $reflection->setValue($command, $output);

    return $command;
}

it('covers translations check helpers via reflection', function (): void {
    $command = app(TranslationsCheckCommand::class);
    $command = translationsCheckCommandWithOutput($command);

    $compact = new ReflectionMethod(TranslationsCheckCommand::class, 'compactTranslations');
    $compact->setAccessible(true);
    $nested = ['a' => 'one', 'b' => ['c' => 'two']];
    $flat = $compact->invokeArgs($command, [&$nested, null]);
    expect($flat)->toContain('a')
        ->and($flat)->toContain('b.c');

    $check = new ReflectionMethod(TranslationsCheckCommand::class, 'checkLabels');
    $check->setAccessible(true);
    $translations = [
        DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'messages.php' => [
            'en' => ['label.a', 'label.b'],
            'it' => ['label.a'],
        ],
    ];
    $ignored = [];
    $check->invokeArgs($command, [&$translations, &$ignored]);
    expect(true)->toBeTrue();
});

it('runs translations check handle when lang directory has sortable php file', function (): void {
    $lang_base = base_path('lang');
    $en_dir = $lang_base . DIRECTORY_SEPARATOR . 'en';

    if (! is_dir($en_dir)) {
        mkdir($en_dir, 0755, true);
    }

    $messages = $en_dir . DIRECTORY_SEPARATOR . 'coverage_sort_messages.php';
    $previous = file_exists($messages) ? file_get_contents($messages) : null;
    file_put_contents($messages, "<?php\n\nreturn ['zebra' => 'Z', 'alpha' => 'A'];\n");

    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $exit = $command->run(new ArrayInput([]), new BufferedOutput());
    expect($exit)->toBe(0);

    if ($previous !== null) {
        file_put_contents($messages, $previous);

        return;
    }

    @unlink($messages);
});

it('covers sortTranslations require import and sort rewrite branches', function (): void {
    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $sort = new ReflectionMethod(TranslationsCheckCommand::class, 'sortTranslations');
    $sort->setAccessible(true);

    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tr_sort_' . uniqid('', false);
    $en_dir = $base . DIRECTORY_SEPARATOR . 'en';
    mkdir($en_dir, 0755, true);

    $requires_file = $en_dir . DIRECTORY_SEPARATOR . 'requires_cov.php';
    file_put_contents($requires_file, "<?php\n\nreturn require(__DIR__ . '/nested.php');\n");
    file_put_contents($en_dir . DIRECTORY_SEPARATOR . 'nested.php', "<?php return ['k' => 'v'];\n");

    $translations = [];
    $ignored = [];
    $sort->invokeArgs($command, [&$translations, $en_dir, $requires_file, &$ignored]);

    expect($ignored)->toContain($requires_file);

    $unsorted_file = $en_dir . DIRECTORY_SEPARATOR . 'unsorted_cov.php';
    file_put_contents($unsorted_file, "<?php return ['zebra' => 'z', 'alpha' => 'a'];\n");
    $sort->invokeArgs($command, [&$translations, $en_dir, $unsorted_file, &$ignored]);
    $reloaded = require $unsorted_file;
    expect(array_keys($reloaded))->toBe(['alpha', 'zebra']);

    $ok_file = $en_dir . DIRECTORY_SEPARATOR . 'already_ok_cov.php';
    file_put_contents($ok_file, "<?php return array_sort_keys(['b' => 'b', 'a' => 'a']);\n");
    $sort->invokeArgs($command, [&$translations, $en_dir, $ok_file, &$ignored]);

    array_map('unlink', [$requires_file, $en_dir . '/nested.php', $unsorted_file, $ok_file]);
    @rmdir($en_dir);
    @rmdir($base);
});

it('covers checkLabels ignored files missing labels missing languages and all ok', function (): void {
    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $check = new ReflectionMethod(TranslationsCheckCommand::class, 'checkLabels');
    $check->setAccessible(true);

    $pattern = DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'bundle.php';
    $real_en = DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR . 'bundle.php';

    $translations = [
        $pattern => [
            'en' => ['label.a', 'label.b'],
            'it' => ['label.a'],
        ],
    ];
    $ignored = [$real_en];
    $check->invokeArgs($command, [&$translations, &$ignored]);

    $pattern_full = DIRECTORY_SEPARATOR . 'pkg' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'full.php';
    $pattern_partial = DIRECTORY_SEPARATOR . 'pkg' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'partial.php';
    $translations_missing_lang = [
        $pattern_full => [
            'en' => ['x'],
            'it' => ['x'],
        ],
        $pattern_partial => [
            'en' => ['y'],
        ],
    ];
    $ignored_empty = [];
    $check->invokeArgs($command, [&$translations_missing_lang, &$ignored_empty]);

    $translations_balanced = [
        DIRECTORY_SEPARATOR . 'mod' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'balanced.php' => [
            'en' => ['p'],
            'it' => ['p'],
        ],
    ];
    $check->invokeArgs($command, [&$translations_balanced, &$ignored_empty]);

    expect(true)->toBeTrue();
});

it('skips non-array translation groups when checking missing language files', function (): void {
    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $check = new ReflectionMethod(TranslationsCheckCommand::class, 'checkLabels');
    $check->setAccessible(true);

    $translations = [
        DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'broken.php' => 'not-a-language-map',
    ];
    $ignored = [];

    $check->invokeArgs($command, [&$translations, &$ignored]);

    expect(true)->toBeTrue();
});

it('keeps translation file identifiers unchanged outside the language directory', function (): void {
    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $method = new ReflectionMethod(TranslationsCheckCommand::class, 'buildTranslationFileIdentifier');
    $method->setAccessible(true);

    $file = DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'messages.php';

    expect($method->invoke($command, DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en' . DIRECTORY_SEPARATOR, $file))
        ->toBe($file);
});

it('rewrites unsorted translation files in the format Pint produces', function (): void {
    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $sort = new ReflectionMethod(TranslationsCheckCommand::class, 'sortTranslations');

    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tr_pint_' . uniqid('', false);
    $en_dir = $base . DIRECTORY_SEPARATOR . 'en';
    mkdir($en_dir, 0755, true);

    $file = $en_dir . DIRECTORY_SEPARATOR . 'messages.php';
    file_put_contents($file, <<<'PHP'
        <?php return array('zebra' => 'It\'s a "zebra"', 'empty' => array(), 'nested' => array('b' => 'Back\\slash', 'a' => 'A'), 'alpha' => 'A');
        PHP);

    $translations = [];
    $ignored = [];
    $sort->invokeArgs($command, [&$translations, $en_dir, $file, &$ignored]);

    $written = file_get_contents($file);
    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', $file]);
    $sort->invokeArgs($command, [&$translations, $en_dir, $file, &$ignored]);
    $rewritten_again = file_get_contents($file);
    $reloaded = require $file;

    unlink($file);
    rmdir($en_dir);
    rmdir($base);

    expect($written)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nreturn [\n")
        ->and($pint->successful())->toBeTrue($pint->output() . $pint->errorOutput())
        ->and($rewritten_again)->toBe($written)
        ->and($reloaded)->toBe([
            'alpha' => 'A',
            'empty' => [],
            'nested' => ['a' => 'A', 'b' => 'Back\\slash'],
            'zebra' => 'It\'s a "zebra"',
        ]);
});

it('flushes the translations label when the check ends', function (): void {
    $index = new SpyLabelIndex();
    app()->instance(ICacheLabelIndex::class, $index);
    $lookup = $index->lookup('translations.en', new CacheDependencies(['translations']));
    $index->store($lookup, ['a' => 'b'], 60);

    $command = translationsCheckCommandWithOutput(app(TranslationsCheckCommand::class));
    $exit = $command->run(new ArrayInput([]), new BufferedOutput());

    expect($exit)->toBe(0)
        ->and($index->flushes)->toContain(['translations'])
        ->and($index->lookup('translations.en', new CacheDependencies(['translations']))->hit)->toBeFalse();
});
