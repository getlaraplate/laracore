<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\SuggestPrompt;
use Laravel\Prompts\TextPrompt;
use Modules\Core\Console\ModelMakeCommand;
use Modules\Core\Tests\Stubs\Console\ModelMakeCoverageFieldsStub;
use Modules\Core\Tests\Stubs\Console\ModelMakeMigrationSpyCommand;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    Prompt::interactive(true);

    $fallbackProp = new ReflectionProperty(Prompt::class, 'shouldFallback');
    $fallbackProp->setValue(null, false);

    $fallbacksProp = new ReflectionProperty(Prompt::class, 'fallbacks');
    $fallbacksProp->setValue(null, []);

    foreach ([TextPrompt::class, SuggestPrompt::class, ConfirmPrompt::class, MultiSelectPrompt::class] as $promptClass) {
        $shouldFallback = new ReflectionProperty($promptClass, 'shouldFallback');
        $shouldFallback->setValue(null, false);

        $fallbacks = new ReflectionProperty($promptClass, 'fallbacks');
        $fallbacks->setValue(null, []);
    }
});

function modelMakeCommandWithOutput(): ModelMakeCommand
{
    $command = app(ModelMakeCommand::class);
    $command->setLaravel(app());
    $output = new OutputStyle(new ArrayInput([]), new BufferedOutput());
    $reflection = new ReflectionProperty(Command::class, 'output');
    $reflection->setValue($command, $output);

    return $command;
}

it('exposes expected command metadata', function (): void {
    $reflection = new ReflectionClass(ModelMakeCommand::class);
    $source = file_get_contents($reflection->getFileName());

    expect($source)->toContain('Create or modify an Eloquent model class')
        ->and($reflection->hasMethod('handle'))->toBeTrue();
});

it('detects reserved names through generator logic', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'isReservedName');
    $method->setAccessible(true);

    expect($method->invoke($command, 'Class'))->toBeTrue()
        ->and($method->invoke($command, 'MyCoverageEntity'))->toBeFalse();
});

it('returns available models array via possibleModels', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'possibleModels');
    $method->setAccessible(true);
    $result = $method->invoke($command);

    expect($result)->toBeArray();
});

it('qualifies classes correctly for app and modules namespaces', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'qualifyClass');
    $method->setAccessible(true);

    $appResult = $method->invoke($command, 'DemoItem');
    $moduleResult = $method->invoke($command, 'Modules\\Core\\Models\\DemoItem');

    expect($appResult)->toBeString()
        ->and($appResult)->toContain('Models')
        ->and($moduleResult)->toBe('Modules\\Core\\Models\\DemoItem');
});

it('builds model path for app namespace', function (): void {
    $command = modelMakeCommandWithOutput();
    $qualify = new ReflectionMethod(ModelMakeCommand::class, 'qualifyClass');
    $pathMethod = new ReflectionMethod(ModelMakeCommand::class, 'getPath');
    $qualify->setAccessible(true);
    $pathMethod->setAccessible(true);

    $qualified = $qualify->invoke($command, 'PathCoverageModel');
    $path = $pathMethod->invoke($command, $qualified);

    expect($path)->toContain('PathCoverageModel.php')
        ->and($path)->toContain('/app/');
});

it('builds model path for modules namespace branch', function (): void {
    $command = modelMakeCommandWithOutput();
    $pathMethod = new ReflectionMethod(ModelMakeCommand::class, 'getPath');
    $pathMethod->setAccessible(true);

    $path = $pathMethod->invoke($command, 'Modules\\Core\\Models\\ModulePathCoverageModel');

    expect($path)->toContain('Core')
        ->and($path)->toContain('/app/Models/')
        ->and($path)->toContain('ModulePathCoverageModel.php');
});

it('handles array_last helper branches', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'array_last');
    $method->setAccessible(true);

    $found = $method->invoke($command, 'x', ['a', 'x', 'b', 'x']);
    $notFound = $method->invoke($command, 'z', ['a', 'x', 'b', 'x']);

    expect($found)->toBe(3)
        ->and($notFound)->toBeFalse();
});

it('adds default sections and strips app models namespace', function (): void {
    $command = modelMakeCommandWithOutput();
    $addDefaultSections = new ReflectionMethod(ModelMakeCommand::class, 'addDefaultSections');
    $stripModelsNamespace = new ReflectionMethod(ModelMakeCommand::class, 'stripModelsNamespace');
    $addDefaultSections->setAccessible(true);
    $stripModelsNamespace->setAccessible(true);

    $input = <<<'PHP'
<?php
namespace App\Models;
class SampleModel extends Model
{
}
PHP;

    $output = $addDefaultSections->invoke($command, 'App\\Models\\SampleModel', $input);
    $stripped = $stripModelsNamespace->invoke($command, 'App\\Models\\SampleModel');

    expect($output)->toContain("public \$table = 'sample_models';")
        ->and($output)->toContain('#region [RELATIONS]')
        ->and($stripped)->toBe('SampleModel');
});

it('recognizes default field types for common naming conventions', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'recognizeDefaultFieldType');
    $method->setAccessible(true);

    expect($method->invoke($command, 'published_at'))->toBe('datetime')
        ->and($method->invoke($command, 'owner_id'))->toBe('integer')
        ->and($method->invoke($command, 'is_active'))->toBe('boolean')
        ->and($method->invoke($command, 'uuid'))->toBeIn(['uuid', 'guid'])
        ->and($method->invoke($command, 'title'))->toBe('string');
});

it('resolves cast mapping and fallback branch', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'getCodeTypeFromCast');
    $method->setAccessible(true);

    expect($method->invoke($command, 'string'))->toBe('string')
        ->and($method->invoke($command, 'json'))->toBe('array')
        ->and($method->invoke($command, 'datetime'))->toBe('datetime');
});

it('injects code snippets at end of class and in fillable/hidden/casts/validations', function (): void {
    $command = modelMakeCommandWithOutput();
    $inject = new ReflectionMethod(ModelMakeCommand::class, 'injectCodeAtTheEnd');
    $fillable = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoFillable');
    $hidden = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoHidden');
    $casts = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoCasts');
    $validations = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoValidations');
    $inject->setAccessible(true);
    $fillable->setAccessible(true);
    $hidden->setAccessible(true);
    $casts->setAccessible(true);
    $validations->setAccessible(true);

    $classCode = <<<'PHP'
<?php
class DemoModel {
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    public $rules = [];
}
PHP;

    $classCode = $inject->invoke($command, $classCode, "\n//snippet");
    $classCode = $fillable->invoke($command, $classCode, 'name');
    $classCode = $hidden->invoke($command, $classCode, 'secret');
    $classCode = $casts->invoke($command, $classCode, 'meta', 'array');
    $classCode = $validations->invoke($command, $classCode, 'name', 'string', false);

    expect($classCode)->toContain("'name'")
        ->and($classCode)->toContain("'secret'")
        ->and($classCode)->toContain("'meta' => 'array'")
        ->and($classCode)->toContain("'always' => [")
        ->and($classCode)->toContain('//snippet');
});

it('injects casts and validations when sections are missing', function (): void {
    $command = modelMakeCommandWithOutput();
    $casts = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoCasts');
    $validations = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoValidations');
    $casts->setAccessible(true);
    $validations->setAccessible(true);

    $classCode = <<<'PHP'
<?php
class NoSections {
}
PHP;

    $classCode = $casts->invoke($command, $classCode, 'meta', 'array');
    $classCode = $validations->invoke($command, $classCode, 'title', 'string', true);

    expect($classCode)->toContain('protected $casts = [')
        ->and($classCode)->toContain("'meta' => 'array'")
        ->and($classCode)->toContain('public $rules = [')
        ->and($classCode)->toContain("'title' => 'string'");
});

it('adds accessors mutators and property annotation', function (): void {
    $command = modelMakeCommandWithOutput();
    $addAccessorMutator = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoAccessorsMutators');
    $addAnnotation = new ReflectionMethod(ModelMakeCommand::class, 'addNewPropertyAnnotation');
    $addAccessorMutator->setAccessible(true);
    $addAnnotation->setAccessible(true);

    $allTypes = (new ReflectionProperty(ModelMakeCommand::class, 'availableTypes'));
    $allTypes->setAccessible(true);
    $flattenTypes = array_merge(...array_values($allTypes->getValue($command)));

    $classCode = <<<'PHP'
<?php
namespace App\Models;

class DemoAnnotated extends Model
{
    #region [ACCESSORS_MUTATORS]
    #endregion
}
PHP;

    $classCode = $addAccessorMutator->invoke($command, $classCode, 'published_at', 'datetime', false, $flattenTypes);
    $classCode = $addAnnotation->invoke($command, 'App\\Models\\DemoAnnotated', $classCode, 'published_at', 'datetime', false);

    expect($classCode)->toContain('setPublishedAtAttribute')
        ->and($classCode)->toContain('@property datetime $published_at');
});

it('injects imports idempotently', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'injectImportClass');
    $method->setAccessible(true);

    $classCode = <<<'PHP'
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ImportDemo extends Model {}
PHP;

    $first = $method->invokeArgs($command, [&$classCode, Illuminate\Support\Carbon::class]);
    $second = $method->invokeArgs($command, [&$classCode, Illuminate\Support\Carbon::class]);

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and(mb_substr_count($classCode, 'use Illuminate\Support\Carbon;'))->toBe(1);
});

it('handles relation helpers and updates relation code', function (): void {
    $command = modelMakeCommandWithOutput();
    $getReversed = new ReflectionMethod(ModelMakeCommand::class, 'getReversedRelationType');
    $proposeInverse = new ReflectionMethod(ModelMakeCommand::class, 'proposeInverseName');
    $updateRelation = new ReflectionMethod(ModelMakeCommand::class, 'updateClassWithNewRelation');
    $getReversed->setAccessible(true);
    $proposeInverse->setAccessible(true);
    $updateRelation->setAccessible(true);

    expect($getReversed->invoke($command, 'ManyToOne'))->toBe('OneToMany')
        ->and($getReversed->invoke($command, 'unknown'))->toBeNull()
        ->and($proposeInverse->invoke($command, 'Post', 'ManyToMany'))->toBe('post')
        ->and($proposeInverse->invoke($command, 'Post', 'unknown'))->toBeNull();

    $classCode = <<<'PHP'
<?php
namespace App\Models;

class RelDemo extends Model
{
    #region [RELATIONS]
    #endregion
}
PHP;
    $tmpPath = sys_get_temp_dir() . '/rel_demo_' . uniqid('', false) . '.php';
    file_put_contents($tmpPath, $classCode);

    $updated = $updateRelation->invoke(
        $command,
        'App\\Models\\RelDemo',
        $classCode,
        $tmpPath,
        'items',
        'relation\\OneToMany',
        'User',
        true,
    );

    expect($updated)->toContain('function items():')
        ->and($updated)->toContain('//relation\\OneToMany');

    @unlink($tmpPath);
});

it('extracts existing class fields from fillable casts hidden and appends', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'getClassFields');
    $method->setAccessible(true);
    $fields = $method->invoke($command, ModelMakeCoverageFieldsStub::class);

    expect($fields)->toBeArray()
        ->and($fields)->toContain('name')
        ->and($fields)->toContain('meta')
        ->and($fields)->toContain('secret')
        ->and($fields)->toContain('status')
        ->and($fields)->toContain('full_name');
});

it('prints relation choice helper and returns selected relation type', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'printRelationsChoiceHelper');
    $method->setAccessible(true);

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static fn (): string => 'ManyToOne');

    $choice = $method->invoke(
        $command,
        'App\\Models\\Post',
        'author',
        'App\\Models\\User',
    );

    expect($choice)->toBe('ManyToOne');
});

it('handles relation details choice branch and normalizes related class', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'handleRelationDetailsChoice');
    $availableClasses = new ReflectionProperty(ModelMakeCommand::class, 'availableClasses');
    $method->setAccessible(true);
    $availableClasses->setAccessible(true);
    $availableClasses->setValue($command, ['App\\Models\\User']);

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static function (): string {
        static $answers = ['App\\Models\\User', 'OneToMany'];
        static $index = 0;

        return $answers[$index++] ?? 'App\\Models\\User';
    });

    $fieldType = 'relation';
    $related = $method->invokeArgs($command, ['App\\Models\\Post', 'author', &$fieldType]);

    expect($fieldType)->toBe('OneToMany')
        ->and($related)->toBe('App\\Models\\User');
});

it('proceeds with model attributes and updates class code', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'proceedWithModelAttributes');
    $method->setAccessible(true);

    $classCode = <<<'PHP'
<?php
namespace App\Models;

class TempCoverageModel extends Model
{
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    public $rules = [];
    #region [ACCESSORS_MUTATORS]
    #endregion
}
PHP;
    $tmpPath = sys_get_temp_dir() . '/model_make_attr_' . uniqid('', false) . '.php';
    file_put_contents($tmpPath, $classCode);

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static function (): string {
        static $answers = ['priority', ''];
        static $index = 0;

        return $answers[$index++] ?? '';
    });

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static fn (): string => 'integer');
    ConfirmPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackUsing(static fn (): bool => false);

    $method->invoke($command, ModelMakeCoverageFieldsStub::class, $classCode, $tmpPath);

    $updated = file_get_contents($tmpPath);
    expect($updated)->toContain("'priority'")
        ->and($updated)->toContain('setPriorityAttribute');

    @unlink($tmpPath);
});

it('proceeds with model attributes adding a relation and optional inverse relation', function (): void {
    $command = app(ModelMakeCommand::class);
    $command->setLaravel(app());
    $suffix = bin2hex(random_bytes(4));
    $parent_short = "Rel{$suffix}Parent";
    $child_short = "Rel{$suffix}Child";
    $parent_class = "App\\Models\\{$parent_short}";
    $child_class = "App\\Models\\{$child_short}";

    $non_interactive_input = new ArrayInput([]);
    $non_interactive_input->setInteractive(false);
    $output = new OutputStyle($non_interactive_input, new BufferedOutput());
    $output_property = new ReflectionProperty(Command::class, 'output');
    $output_property->setAccessible(true);
    $input_property = new ReflectionProperty(Command::class, 'input');
    $input_property->setAccessible(true);
    $output_property->setValue($command, $output);
    $input_property->setValue($command, $non_interactive_input);

    $get_path = new ReflectionMethod(ModelMakeCommand::class, 'getPath');
    $get_path->setAccessible(true);
    $parent_path = $get_path->invoke($command, $parent_class);
    $child_path = $get_path->invoke($command, $child_class);
    @mkdir(dirname($parent_path), 0777, true);
    @mkdir(dirname($child_path), 0777, true);

    $base_code = <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RelTpl extends Model
{
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    public $rules = [];
    #region [RELATIONS]
    #endregion
}
PHP;

    file_put_contents($parent_path, str_replace('RelTpl', $parent_short, $base_code));
    file_put_contents($child_path, str_replace('RelTpl', $child_short, $base_code));

    $parent_code = (string) file_get_contents($parent_path);
    $strip_ns = new ReflectionMethod(ModelMakeCommand::class, 'stripModelsNamespace');
    $propose_inverse = new ReflectionMethod(ModelMakeCommand::class, 'proposeInverseName');
    $strip_ns->setAccessible(true);
    $propose_inverse->setAccessible(true);
    $short_parent = $strip_ns->invoke($command, $parent_class);
    $expected_inverse = $propose_inverse->invoke($command, $short_parent, 'OneToMany');
    expect($expected_inverse)->not->toBe('');

    $available_classes = new ReflectionProperty(ModelMakeCommand::class, 'availableClasses');
    $available_classes->setAccessible(true);
    $available_classes->setValue($command, [$child_class]);

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static function () use ($expected_inverse): string {
        static $index = 0;

        return match ($index++) {
            0 => 'author',
            1 => $expected_inverse,
            2 => '',
            default => '',
        };
    });

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static function () use ($child_class): string {
        static $index = 0;

        return match ($index++) {
            0 => 'relation\\ManyToOne',
            1 => $child_class,
            default => 'string',
        };
    });

    $method = new ReflectionMethod(ModelMakeCommand::class, 'proceedWithModelAttributes');
    $method->setAccessible(true);
    $method->invoke($command, $parent_class, $parent_code, $parent_path);

    $updated_parent = (string) file_get_contents($parent_path);
    $updated_child = (string) file_get_contents($child_path);
    expect($updated_parent)->toContain('function author():')
        ->and($updated_child)->toContain('function ' . $expected_inverse . '():');

    @unlink($parent_path);
    @unlink($child_path);
});

it('updates class with new property including fillable casts validations and annotation', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'updateClassWithNewProperty');
    $typesProperty = new ReflectionProperty(ModelMakeCommand::class, 'availableTypes');
    $method->setAccessible(true);
    $typesProperty->setAccessible(true);

    $allTypes = array_merge(...array_values($typesProperty->getValue($command)));
    $classCode = <<<'PHP'
<?php
namespace App\Models;

class TempRichModel extends Model
{
    protected $fillable = [];
    protected $hidden = [];
    protected $casts = [];
    public $rules = [];
    #region [ACCESSORS_MUTATORS]
    #endregion
}
PHP;
    $tmpPath = sys_get_temp_dir() . '/model_make_update_' . uniqid('', false) . '.php';
    file_put_contents($tmpPath, $classCode);

    $updated = $method->invoke(
        $command,
        'App\\Models\\TempRichModel',
        $classCode,
        $tmpPath,
        'published_at',
        'datetime',
        false,
        $allTypes,
    );

    expect($updated)->toContain("'published_at'")
        ->and($updated)->toContain("'published_at' => 'datetime'")
        ->and($updated)->toContain("'published_at' => 'date|required'")
        ->and($updated)->toContain('@property datetime $published_at');

    @unlink($tmpPath);
});

it('creates direct relation method and updates source class code', function (): void {
    $command = modelMakeCommandWithOutput();
    $updateRelation = new ReflectionMethod(ModelMakeCommand::class, 'updateClassWithNewRelation');
    $getPath = new ReflectionMethod(ModelMakeCommand::class, 'getPath');
    $inputProperty = new ReflectionProperty(Command::class, 'input');
    $updateRelation->setAccessible(true);
    $getPath->setAccessible(true);
    $inputProperty->setAccessible(true);

    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $inputProperty->setValue($command, $input);

    $unique = 'RelCov' . uniqid('', false);
    $sourceClass = "App\\Models\\{$unique}Source";
    $targetClass = "{$unique}Target";
    $fullTargetClass = "App\\Models\\{$targetClass}";

    $sourcePath = $getPath->invoke($command, $sourceClass);
    $targetPath = $getPath->invoke($command, $fullTargetClass);
    @mkdir(dirname($sourcePath), 0777, true);
    @mkdir(dirname($targetPath), 0777, true);

    $baseCode = <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TempClass extends Model
{
    #region [RELATIONS]
    #endregion
}
PHP;

    file_put_contents($sourcePath, str_replace('TempClass', "{$unique}Source", $baseCode));
    file_put_contents($targetPath, str_replace('TempClass', $targetClass, $baseCode));

    $sourceCode = file_get_contents($sourcePath);
    $updatedSource = $updateRelation->invoke(
        $command,
        $sourceClass,
        $sourceCode,
        $sourcePath,
        'target',
        'relation\\ManyToOne',
        $targetClass,
        true,
    );

    $updatedTarget = file_get_contents($targetPath);

    expect($updatedSource)->toContain('function target():')
        ->and($updatedTarget)->not->toContain('function target():');

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('creates migration command payload based on class state', function (): void {
    $command = modelMakeCommandWithOutput();
    $createMigration = new ReflectionMethod(ModelMakeCommand::class, 'createMigration');
    $isNewClassProperty = new ReflectionProperty(ModelMakeCommand::class, 'isNewClass');
    $inputProperty = new ReflectionProperty(Command::class, 'input');
    $createMigration->setAccessible(true);
    $isNewClassProperty->setAccessible(true);
    $inputProperty->setAccessible(true);

    $unique = 'MigCov' . uniqid('', false);
    $input = new ArrayInput(['name' => $unique, '--pivot' => false]);
    $input->bind($command->getDefinition());
    $inputProperty->setValue($command, $input);

    $isNewClassProperty->setValue($command, true);
    ModelMakeMigrationSpyCommand::$lastArguments = [];
    $application = new SymfonyApplication();
    $application->addCommand(new ModelMakeMigrationSpyCommand());
    $command->setApplication($application);
    $createMigration->invoke($command);

    $table = Illuminate\Support\Str::snake(Illuminate\Support\Str::pluralStudly(class_basename($unique)));
    expect(ModelMakeMigrationSpyCommand::$lastArguments['name'])->toBe('create_' . $table . '_table')
        ->and(ModelMakeMigrationSpyCommand::$lastArguments['create'])->toBe($table)
        ->and(ModelMakeMigrationSpyCommand::$lastArguments['fullpath'])->toBeTrue();
});

it('creates migration payload for update and pivot branches', function (): void {
    $command = modelMakeCommandWithOutput();
    $createMigration = new ReflectionMethod(ModelMakeCommand::class, 'createMigration');
    $isNewClassProperty = new ReflectionProperty(ModelMakeCommand::class, 'isNewClass');
    $inputProperty = new ReflectionProperty(Command::class, 'input');
    $createMigration->setAccessible(true);
    $isNewClassProperty->setAccessible(true);
    $inputProperty->setAccessible(true);

    $application = new SymfonyApplication();
    $application->addCommand(new ModelMakeMigrationSpyCommand());
    $command->setApplication($application);

    $unique = 'MigUpdate' . uniqid('', false);
    $input = new ArrayInput(['name' => $unique, '--pivot' => true]);
    $input->bind($command->getDefinition());
    $inputProperty->setValue($command, $input);
    $isNewClassProperty->setValue($command, false);
    ModelMakeMigrationSpyCommand::$lastArguments = [];

    $createMigration->invoke($command);

    $table = Illuminate\Support\Str::singular(Illuminate\Support\Str::snake(Illuminate\Support\Str::pluralStudly(class_basename($unique))));
    expect(ModelMakeMigrationSpyCommand::$lastArguments['name'])->toBe('update_' . $table . '_table')
        ->and(ModelMakeMigrationSpyCommand::$lastArguments['update'])->toBe($table);
});

it('runs handle for reserved name branch safely', function (): void {
    $command = modelMakeCommandWithOutput();
    $exit = $command->run(new ArrayInput(['name' => 'Class']), new BufferedOutput());

    expect($exit)->toBe(0);
});

it('returns empty fields when class does not exist', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'getClassFields');
    $method->setAccessible(true);

    expect($method->invoke($command, 'App\\Models\\NonExistentModelForCoverage'))->toBe([]);
});

it('recognizes guid default field type', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'recognizeDefaultFieldType');
    $method->setAccessible(true);

    expect($method->invoke($command, 'guid'))->toBe('guid');
});

it('resolves cast mapping to empty string for unknown type key', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'getCodeTypeFromCast');
    $method->setAccessible(true);

    expect($method->invoke($command, 'definitely_not_a_registered_field_type'))->toBe('');
});

it('leaves non app models namespace unchanged when stripping', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'stripModelsNamespace');
    $method->setAccessible(true);

    $moduleClass = 'Modules\\Core\\Models\\SomeEntity';
    expect($method->invoke($command, $moduleClass))->toBe($moduleClass);
});

it('injects relation snippet at end of class when relations region is missing', function (): void {
    $command = modelMakeCommandWithOutput();
    $updateRelation = new ReflectionMethod(ModelMakeCommand::class, 'updateClassWithNewRelation');
    $updateRelation->setAccessible(true);

    $classCode = <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoRelationsRegionModel extends Model
{
}
PHP;
    $tmpPath = sys_get_temp_dir() . '/model_make_no_rel_' . uniqid('', false) . '.php';
    file_put_contents($tmpPath, $classCode);

    $updated = $updateRelation->invoke(
        $command,
        'App\\Models\\NoRelationsRegionModel',
        $classCode,
        $tmpPath,
        'items',
        'relation\\OneToMany',
        'User',
        true,
    );

    expect($updated)->toContain('function items():')
        ->and($updated)->toContain('//relation\\OneToMany')
        ->and($updated)->not->toContain('#region [RELATIONS]');

    @unlink($tmpPath);
});

it('does not add import when class file has no use statements', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'injectImportClass');
    $method->setAccessible(true);

    $classCode = <<<'PHP'
<?php

namespace App\Models;

class BareModel {}
PHP;
    $added = $method->invokeArgs($command, [&$classCode, Illuminate\Support\Carbon::class]);

    expect($added)->toBeFalse()
        ->and($classCode)->not->toContain('use Illuminate\Support\Carbon;');
});

it('creates inverse relation on related model when confirmed', function (): void {
    $command = app(ModelMakeCommand::class);
    $command->setLaravel(app());
    $non_interactive_input = new ArrayInput([]);
    $non_interactive_input->setInteractive(false);
    $output = new OutputStyle($non_interactive_input, new BufferedOutput());
    $outputProperty = new ReflectionProperty(Command::class, 'output');
    $outputProperty->setAccessible(true);
    $outputProperty->setValue($command, $output);
    $inputProperty = new ReflectionProperty(Command::class, 'input');
    $inputProperty->setAccessible(true);
    $inputProperty->setValue($command, $non_interactive_input);

    $updateRelation = new ReflectionMethod(ModelMakeCommand::class, 'updateClassWithNewRelation');
    $getPath = new ReflectionMethod(ModelMakeCommand::class, 'getPath');
    $updateRelation->setAccessible(true);
    $getPath->setAccessible(true);

    $unique = 'InvCov' . uniqid('', false);
    $sourceClass = "App\\Models\\{$unique}Parent";
    $targetClass = "{$unique}Child";
    $fullTargetClass = "App\\Models\\{$targetClass}";

    $sourcePath = $getPath->invoke($command, $sourceClass);
    $targetPath = $getPath->invoke($command, $fullTargetClass);
    @mkdir(dirname($sourcePath), 0777, true);
    @mkdir(dirname($targetPath), 0777, true);

    $baseCode = <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TempInv extends Model
{
    #region [RELATIONS]
    #endregion
}
PHP;

    file_put_contents($sourcePath, str_replace('TempInv', "{$unique}Parent", $baseCode));
    file_put_contents($targetPath, str_replace('TempInv', $targetClass, $baseCode));

    $sourceCode = file_get_contents($sourcePath);

    $stripNs = new ReflectionMethod(ModelMakeCommand::class, 'stripModelsNamespace');
    $proposeInverse = new ReflectionMethod(ModelMakeCommand::class, 'proposeInverseName');
    $stripNs->setAccessible(true);
    $proposeInverse->setAccessible(true);
    $short_source = $stripNs->invoke($command, $sourceClass);
    $expected_inverse_method = $proposeInverse->invoke($command, $short_source, 'OneToMany');
    expect($expected_inverse_method)->not->toBeNull();

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => $expected_inverse_method);

    $updatedSource = $updateRelation->invoke(
        $command,
        $sourceClass,
        $sourceCode,
        $sourcePath,
        'child',
        'relation\\ManyToOne',
        $targetClass,
        false,
    );

    $updatedTarget = file_get_contents($targetPath);

    expect($updatedSource)->toContain('function child():')
        ->and($updatedTarget)->toContain('function ' . $expected_inverse_method . '():');

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('injects accessor mutator at class end when accessors region is absent', function (): void {
    $command = modelMakeCommandWithOutput();
    $add_accessor_mutator = new ReflectionMethod(ModelMakeCommand::class, 'addPropertyIntoAccessorsMutators');
    $add_accessor_mutator->setAccessible(true);

    $all_types_prop = new ReflectionProperty(ModelMakeCommand::class, 'availableTypes');
    $all_types_prop->setAccessible(true);
    $flatten_types = array_merge(...array_values($all_types_prop->getValue($command)));

    $class_code = <<<'PHP'
<?php
namespace App\Models;

class NoAccessorRegionModel extends Model
{
}
PHP;

    $updated = $add_accessor_mutator->invoke($command, $class_code, 'title', 'string', false, $flatten_types);

    expect($updated)->toContain('setTitleAttribute')
        ->and($updated)->not->toContain('#region [ACCESSORS_MUTATORS]');
});

it('normalizes reversed relation types with relation namespace prefix', function (): void {
    $command = modelMakeCommandWithOutput();
    $get_reversed = new ReflectionMethod(ModelMakeCommand::class, 'getReversedRelationType');
    $propose_inverse = new ReflectionMethod(ModelMakeCommand::class, 'proposeInverseName');
    $get_reversed->setAccessible(true);
    $propose_inverse->setAccessible(true);

    expect($get_reversed->invoke($command, 'relation\\ManyToOne'))->toBe('OneToMany')
        ->and($get_reversed->invoke($command, 'relation\\OneToMany'))->toBe('ManyToOne')
        ->and($get_reversed->invoke($command, 'relation\\OneToOne'))->toBe('OneToOne')
        ->and($get_reversed->invoke($command, 'relation\\ManyToMany'))->toBe('ManyToMany')
        ->and($get_reversed->invoke($command, 'relation\\Unknown'))->toBeNull()
        ->and($propose_inverse->invoke($command, 'Article', 'ManyToOne'))->toBe('articles')
        ->and($propose_inverse->invoke($command, 'Article', 'OneToOne'))->toBe('articles')
        ->and($propose_inverse->invoke($command, 'Article', 'UnknownRel'))->toBeNull();
});

it('returns false from askForPropertyName when name is empty under non interactive prompt', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'askForPropertyName');
    $method->setAccessible(true);

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => '');

    $result = $method->invoke($command, [], []);

    expect($result)->toBeFalse();
});

it('validates new property name and field type inputs via dedicated helpers', function (): void {
    $command = modelMakeCommandWithOutput();
    $validate_name = new ReflectionMethod(ModelMakeCommand::class, 'validateNewPropertyNameInput');
    $validate_type = new ReflectionMethod(ModelMakeCommand::class, 'validateFieldTypeInput');
    $validate_name->setAccessible(true);
    $validate_type->setAccessible(true);

    expect($validate_name->invoke($command, 'taken', ['taken'], []))->toBe('The "taken" property already exists.')
        ->and($validate_name->invoke($command, 'fresh', [], []))->toBeNull()
        ->and($validate_type->invoke($command, 'not_a_type', ['integer', 'string']))->toBe('Invalid type "not_a_type"')
        ->and($validate_type->invoke($command, 'integer', ['integer', 'string']))->toBeNull();
});

it('fills missing model name via promptForMissingArguments using completion fallback', function (): void {
    $command = modelMakeCommandWithOutput();
    $models = models(false);
    expect($models)->not->toBeEmpty();
    $pick = $models[0];

    $input = new ArrayInput([]);
    $input->bind($command->getDefinition());
    $command->setInput($input);

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static fn (): string => $pick);
    MultiSelectPrompt::fallbackWhen(true);
    MultiSelectPrompt::fallbackUsing(static fn (): array => ['migration']);

    $prompt = new ReflectionMethod(ModelMakeCommand::class, 'promptForMissingArguments');
    $prompt->setAccessible(true);
    $prompt->invoke($command, $input, new BufferedOutput());

    expect($input->getArgument('name'))->toBe($pick);
});

it('applies afterPromptingForMissingArguments branches for options multiselect and early exits', function (): void {
    $command = modelMakeCommandWithOutput();
    $after = new ReflectionMethod(ModelMakeCommand::class, 'afterPromptingForMissingArguments');
    $after->setAccessible(true);
    $available = new ReflectionProperty(ModelMakeCommand::class, 'availableClasses');
    $available->setAccessible(true);

    MultiSelectPrompt::fallbackWhen(true);
    MultiSelectPrompt::fallbackUsing(static fn (): array => ['factory', 'form requests', 'resource controller']);

    $input = new ArrayInput(['name' => 'BrandNewModelForPromptCov']);
    $input->bind($command->getDefinition());
    $available->setValue($command, []);
    $command->setInput($input);

    $after->invoke($command, $input, new BufferedOutput());

    expect($input->getOption('factory'))->toBeTrue()
        ->and($input->getOption('requests'))->toBeTrue()
        ->and($input->getOption('resource'))->toBeTrue();

    $input_reserved = new ArrayInput(['name' => 'Class']);
    $input_reserved->bind($command->getDefinition());
    $command->setInput($input_reserved);
    $before_factory = $input_reserved->getOption('factory');
    $after->invoke($command, $input_reserved, new BufferedOutput());
    expect($input_reserved->getOption('factory'))->toBe($before_factory);

    $input_options = new ArrayInput(['name' => 'OtherCov', '--factory' => true]);
    $input_options->bind($command->getDefinition());
    $command->setInput($input_options);
    $after->invoke($command, $input_options, new BufferedOutput());
    expect($input_options->getOption('factory'))->toBeTrue()
        ->and($input_options->getOption('requests'))->toBeFalse();

    $available->setValue($command, ['ExistingCovModel']);
    MultiSelectPrompt::fallbackUsing(static fn (): array => ['seed']);
    $input_existing = new ArrayInput(['name' => 'ExistingCovModel']);
    $input_existing->bind($command->getDefinition());
    $command->setInput($input_existing);
    $after->invoke($command, $input_existing, new BufferedOutput());
    expect($input_existing->getOption('seed'))->toBeTrue();
});

it('returns early from handleAskInverseRelation when user declines or inverse name is empty', function (): void {
    $command = app(ModelMakeCommand::class);
    $command->setLaravel(app());
    $output_property = new ReflectionProperty(Command::class, 'output');
    $output_property->setAccessible(true);
    $input_property = new ReflectionProperty(Command::class, 'input');
    $input_property->setAccessible(true);

    $method = new ReflectionMethod(ModelMakeCommand::class, 'handleAskInverseRelation');
    $method->setAccessible(true);

    $decline_output = new class(new ArrayInput([]), new BufferedOutput()) extends OutputStyle
    {
        #[Override]
        public function confirm(string $question, bool $default = true): bool
        {
            return false;
        }
    };
    $output_property->setValue($command, $decline_output);
    $input_property->setValue($command, new ArrayInput([]));
    expect(fn () => $method->invoke($command, 'App\\Models\\Post', 'User', 'App\\Models\\User', 'relation\\ManyToOne'))
        ->not->toThrow(Throwable::class);

    $non_interactive_input = new ArrayInput([]);
    $non_interactive_input->setInteractive(false);
    $standard_output = new OutputStyle($non_interactive_input, new BufferedOutput());
    $output_property->setValue($command, $standard_output);
    $input_property->setValue($command, $non_interactive_input);
    expect(fn () => $method->invoke($command, 'App\\Models\\', 'User', 'App\\Models\\User', 'relation\\ManyToOne'))
        ->not->toThrow(Throwable::class);

    expect(fn () => $method->invoke($command, 'App\\Models\\Post', 'User', 'App\\Models\\User', 'relation\\UnmappedRelation'))
        ->not->toThrow(Throwable::class);
});

it('throws when relationship field is missing related class in proceedWithModelAttributes', function (): void {
    $command = modelMakeCommandWithOutput();
    $method = new ReflectionMethod(ModelMakeCommand::class, 'proceedWithModelAttributes');
    $method->setAccessible(true);

    $class_name = 'TempRelMissing' . bin2hex(random_bytes(4));
    $class_fqcn = 'App\\Models\\' . $class_name;
    $class_code = <<<PHP
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$class_name} extends Model
{
    protected \$fillable = [];
    protected \$hidden = [];
    protected \$casts = [];
    public \$rules = [];
    #region [ACCESSORS_MUTATORS]
    #endregion
    #region [RELATIONS]
    #endregion
}
PHP;
    $tmp_path = sys_get_temp_dir() . '/model_make_rel_miss_' . uniqid('', false) . '.php';
    file_put_contents($tmp_path, $class_code);

    TextPrompt::fallbackWhen(true);
    TextPrompt::fallbackUsing(static fn (): string => 'orphan_rel');

    SuggestPrompt::fallbackWhen(true);
    SuggestPrompt::fallbackUsing(static function (): string {
        static $n = 0;
        $n++;

        return match ($n) {
            1 => 'relation\\ManyToOne',
            2 => '',
            default => 'integer',
        };
    });

    expect(fn () => $method->invoke($command, $class_fqcn, $class_code, $tmp_path))
        ->toThrow(InvalidArgumentException::class, 'Missing related class attribute');

    @unlink($tmp_path);
});
