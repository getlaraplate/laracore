<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Support\Str;
use Modules\Core\Cache\Labels\CacheLabel;
use Modules\Core\Contracts\ICacheLabelIndex;
use Modules\Core\Overrides\Command;
use Override;
use Symfony\Component\Console\Command\Command as BaseCommand;

final class TranslationsCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    #[Override]
    protected $signature = 'lang:check-translations';

    /**
     * The console command description.
     *
     * @var string
     */
    #[Override]
    protected $description = 'Verify languages, labels coherence and sort all translations keys <fg=green>(⚡ Modules\Core)</fg=green>';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->output->writeln(['', 'Sorting translations', '']);
        $languages = translations(true);
        $grouped_translations = [];
        $to_be_ignored_files = [];

        foreach ($languages as $lang) {
            $file = $lang . DIRECTORY_SEPARATOR . '*.php';
            $files = glob($file);

            foreach ($files as $file) {
                $this->sortTranslations($grouped_translations, $lang, $file, $to_be_ignored_files);
            }
        }

        $this->checkLabels($grouped_translations, $to_be_ignored_files);

        $this->output->writeln('');

        resolve(ICacheLabelIndex::class)->flush(CacheLabel::translations());

        return BaseCommand::SUCCESS;
    }

    private function compactTranslations(array $label_groups, ?string $subgroup = null): array
    {
        $mapped = [];

        foreach (array_keys($label_groups) as $key) {
            $fullpath = in_array($subgroup, [null, '', '0'], true) ? $key : sprintf('%s.%s', $subgroup, $key);

            if (gettype($label_groups[$key]) === 'string') {
                $mapped[] = $fullpath;
            } else {
                $mapped = array_merge($mapped, $this->compactTranslations($label_groups[$key], $fullpath));
            }
        }

        return $mapped;
    }

    private function sortTranslations(array &$grouped_translations, string $lang, string $file, array &$to_be_ignored_files): void
    {
        $langname = basename($lang);
        $lang_directory = mb_rtrim($lang, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $contains_requires = Str::contains(file_get_contents($file), 'require(__DIR__');
        $labels = require $file;
        $file_identifier = $this->buildTranslationFileIdentifier($lang_directory, $file);

        if (! array_key_exists($file_identifier, $grouped_translations)) {
            $grouped_translations[$file_identifier] = [];
        }

        $grouped_translations[$file_identifier][$langname] = $this->compactTranslations($labels);

        if ($contains_requires) {
            $to_be_ignored_files[] = $file;
            $this->output->writeln(sprintf('<comment>%s imports another language</comment>', $file));

            return;
        }

        $stringified = var_export($labels, true);
        $imported = var_export(array_sort_keys($labels), true);

        if ($stringified === $imported) {
            $this->output->writeln(sprintf('<info>%s is ok</info>', $file));

            return;
        }

        file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $this->exportArray(array_sort_keys($labels)) . ";\n");
        $this->output->writeln(sprintf('<info>%s has been sorted</info>', $file));
    }

    private function checkLabels(array &$grouped_translations, array &$to_be_ignored_files): void
    {
        $this->output->writeln(['', 'Checking labels', '']);
        $all_languages = [];

        // prima accumulo tutte le lingue
        foreach ($grouped_translations as $file => $langs) {
            if (! is_array($langs)) {
                continue;
            }

            $all_labels = [];

            // prima accumulo tutte le labels
            foreach ($langs as $lang => $labels) {
                if (! in_array($lang, $all_languages, true)) {
                    $all_languages[] = $lang;
                }

                foreach ($labels as $label) {
                    if (! in_array($label, $all_labels, true)) {
                        $all_labels[] = $label;
                    }
                }
            }

            // poi controllo in quale file mancano labels
            foreach ($langs as $lang => $labels) {
                $realfile = str_replace('*', $lang, $file);

                if (in_array($realfile, $to_be_ignored_files, true)) {
                    $this->output->writeln(sprintf('<comment>%s imports another language</comment>', $realfile));

                    continue;
                }

                $diff = array_diff($all_labels, $labels);

                if ($diff === []) {
                    $this->output->writeln(sprintf('<info>%s labels are ok</info>', $realfile));
                } else {
                    $joined_labels = implode(', ', $diff);
                    $this->output->writeln(sprintf('<error>%s is missing the current labels: %s</error>', $realfile, $joined_labels));
                }
            }
        }

        $this->output->writeln(['', 'Checking languages', '']);
        $any_missing_file = false;

        // poi controllo di quale file manca la lingua
        foreach ($grouped_translations as $file => $langs) {
            if (! is_array($langs)) {
                continue;
            }

            $diff = array_diff($all_languages, array_keys($langs));

            if ($diff !== []) {
                $any_missing_file = true;

                foreach ($diff as $lang) {
                    $realfile = str_replace('*', $lang, $file);
                    $this->output->writeln(sprintf('<error>%s is missing</error>', $realfile));
                }
            }
        }

        if (! $any_missing_file) {
            $this->output->writeln('<info>All languages are correctly translated</info>');
        }
    }

    /**
     * Export an array as PHP source in the format Pint produces: short array syntax,
     * four-space indentation, single-quoted strings and trailing commas.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function exportArray(array $values, int $depth = 1): string
    {
        if ($values === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $lines = [];

        foreach ($values as $key => $value) {
            $exported_value = is_array($value) ? $this->exportArray($value, $depth + 1) : $this->exportScalar($value);
            $lines[] = $indent . var_export($key, true) . ' => ' . $exported_value . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth - 1) . ']';
    }

    private function exportScalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
    }

    private function buildTranslationFileIdentifier(string $lang_directory, string $file): string
    {
        if (! str_starts_with($file, $lang_directory)) {
            return $file;
        }

        $relative_path = mb_substr($file, mb_strlen($lang_directory));

        return dirname(mb_rtrim($lang_directory, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $relative_path;
    }
}
