<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

use InvalidArgumentException;
use Modules\Core\Contracts\RefreshesConfigCache;
use Modules\Core\Overrides\Command;
use Modules\Core\Support\Env\EnvEntry;
use Modules\Core\Support\Env\EnvFile;
use Modules\Core\Support\Env\EnvValue;
use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command as BaseCommand;

/**
 * Aligns a `.env` with `.env.example` after an update: proposes the variables of the example that the
 * `.env` lacks, asks the value of each one the user picks and writes it at once. An empty answer ends the
 * loop; if anything was written to the application's own `.env`, it then offers to rebuild the configuration cache.
 */
final class EnvFillCommand extends Command
{
    #[Override]
    protected $signature = 'env:fill
                            {--file= : The .env file to complete (default: the application .env)}
                            {--example= : The file listing every variable (default: the application .env.example)}';

    #[Override]
    protected $description = 'Add the variables of .env.example that the .env lacks, one prompt at a time <fg=green>(⚡ Modules\Core)</fg=green>';

    public function handle(RefreshesConfigCache $refresher): int
    {
        $target = $this->pathOption('file', base_path('.env'));

        try {
            $env = EnvFile::read($target);
            $example = EnvFile::read($this->pathOption('example', base_path('.env.example')));
            $missing = $env->missingFrom($example);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return BaseCommand::FAILURE;
        }

        if (! is_writable($target)) {
            $this->components->error("The file {$target} cannot be written.");

            return BaseCommand::FAILURE;
        }

        if ($missing === []) {
            $this->components->info('Nothing missing: the .env defines every variable of .env.example.');

            return BaseCommand::SUCCESS;
        }

        $this->components->info(count($missing) . ' ' . (count($missing) === 1 ? 'variable' : 'variables') . ' missing from ' . $target . '.');

        if (! $this->canAsk()) {
            $this->components->error('Run the command interactively: every variable needs an answer, and there is no terminal to ask on (use ssh -t, or run it in a terminal).');

            return BaseCommand::FAILURE;
        }

        $written = [];
        $status = BaseCommand::SUCCESS;

        while ($missing !== []) {
            $name = suggest(
                label: 'Variable to set',
                options: $missing,
                placeholder: 'Press Enter with no name to finish',
                hint: count($missing) . ' missing',
            );

            if ($name === '') {
                break;
            }

            if (! in_array($name, $missing, true)) {
                $this->components->error("{$name} is not one of the missing variables.");

                continue;
            }

            try {
                $this->writeAnswer($env, $example, $name);
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $this->components->error($exception->getMessage());
                $status = BaseCommand::FAILURE;

                break;
            }

            $missing = array_values(array_diff($missing, [$name]));
            $written[] = $name;
        }

        $this->summarize($written);

        if ($written !== [] && $this->isApplicationEnv($target) && confirm(
            label: 'Run config:clear and config:cache now?',
            default: $this->refreshesCacheByDefault(),
            hint: 'Do not leave a cached configuration on a machine that runs the test suite.',
        )) {
            try {
                $refresher->refresh();
                $this->components->info('The configuration cache was rebuilt.');
            } catch (RuntimeException $exception) {
                $this->components->error('The configuration cache was not rebuilt: ' . $exception->getMessage() . '. Run config:clear and config:cache yourself.');
                $status = BaseCommand::FAILURE;
            }
        }

        return $status;
    }

    /**
     * The default answer of the cache question: yes where the configuration is cached, in production.
     */
    public function refreshesCacheByDefault(): bool
    {
        return app()->isProduction();
    }

    /**
     * Whether a prompt can be answered: the way Laravel decides to ask (a terminal on standard input, or a test), not
     * only `--no-interaction`, because without a terminal every prompt would answer its default and write nothing.
     */
    private function canAsk(): bool
    {
        return $this->input->isInteractive() && (app()->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    /**
     * Asks the value of `$name` and appends it. An answer equal to the default offered, the value of the example
     * line, writes the example's own text, so a quoted value or a `${VARIABLE}` reference stays what the example says.
     */
    private function writeAnswer(EnvFile $env, EnvFile $example, string $name): void
    {
        $entry = $example->entry($name);
        $hint = $entry?->comment ?? '';

        if (EnvValue::isSecretName($name)) {
            $env->append($name, password(label: $name, hint: $hint));

            return;
        }

        $default = $entry?->value ?? '';
        $answer = text(label: $name, default: $default, hint: $hint);

        if ($entry instanceof EnvEntry && $answer === $default) {
            $env->appendRaw($name, $entry->raw);

            return;
        }

        $env->append($name, $answer);
    }

    /**
     * @param  list<string>  $written
     */
    private function summarize(array $written): void
    {
        if ($written === []) {
            $this->components->info('No variable was written.');

            return;
        }

        $this->components->info(count($written) . ' written: ' . implode(', ', $written));
    }

    private function isApplicationEnv(string $target): bool
    {
        $application = base_path('.env');

        return realpath($target) === realpath($application) && realpath($application) !== false;
    }

    private function pathOption(string $option, string $default): string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
