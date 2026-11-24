<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Utils;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IOptimisticLockableModel;
use Modules\Core\Helpers\LocaleContext;
use Override;

/**
 * Moves between a record and the state of a Filament form what the model keeps out of `$fillable` on
 * purpose and the form still edits: the translated fields, which the setter routes to the translation
 * table of the current locale, the sort column, and the guarded attributes a page names. Mass assignment
 * discards them (and throws outside production), so they are assigned one by one; anything else the model
 * guards stays guarded. The edit page fills its form from the record's own columns, which leave the same
 * attributes out, so they are added to the form state too.
 *
 * @phpstan-require-extends \Filament\Resources\Pages\Page
 */
trait AssignsAttributesOwnedByTheForm
{
    use HasFilamentFormDataSanitizer {
        HasFilamentFormDataSanitizer::mutateFormDataBeforeFill as private sanitizedFormDataBeforeFill;
    }

    /**
     * Translated fields are written in the content locale, so the page says which one it is.
     */
    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        if (! method_exists(static::getResource()::getModel(), 'getTranslatableFields')) {
            return parent::getSubheading();
        }

        return (string) __('app.form.translationLocale', ['locale' => LocaleContext::get()]);
    }

    /**
     * Attributes the model guards from mass assignment that the form of this page still edits, such as the
     * foreign key of a relationship select.
     *
     * @return list<string>
     */
    protected function guardedAttributesTheFormEdits(): array
    {
        return [];
    }

    /**
     * Adds the translated fields of the current locale, without the fallback: a missing translation stays
     * empty instead of showing another language, which saving would copy into this one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = $this->sanitizedFormDataBeforeFill($data);
        $record = $this->getRecord();

        foreach ([$this->sortColumnOf($record), ...$this->guardedAttributesTheFormEdits()] as $attribute) {
            if ($attribute !== null) {
                $data[$attribute] = $record->getAttribute($attribute);
            }
        }

        if (! method_exists($record, 'getTranslatableFields')) {
            return $data;
        }

        $translation = $record->getTranslation(LocaleContext::get(), false);

        foreach ($record::getTranslatableFields() as $field) {
            $value = $translation?->getAttribute($field);
            // Structured fields, the components, are edited as JSON text.
            $data[$field] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function fillFromFormState(Model $record, array $data): void
    {
        // The form carries the lock version as a hidden field; the model owns it and starts a new record at 1.
        if ($record instanceof IOptimisticLockableModel) {
            unset($data[$record::lockVersionColumn()]);
        }

        $sort_column = $this->sortColumnOf($record);
        $guarded = $this->guardedAttributesTheFormEdits();

        $owned = array_filter(
            $data,
            static fn (string $attribute): bool => $attribute === $sort_column
                || in_array($attribute, $guarded, true)
                || (method_exists($record, 'isTranslatableField') && $record->isTranslatableField($attribute)),
            ARRAY_FILTER_USE_KEY,
        );

        $record->fill(array_diff_key($data, $owned));

        foreach ($owned as $attribute => $value) {
            $record->setAttribute($attribute, $value);
        }
    }

    private function sortColumnOf(Model $record): ?string
    {
        return method_exists($record, 'determineOrderColumnName') ? $record->determineOrderColumnName() : null;
    }
}
