<?php

declare(strict_types=1);

namespace Modules\Core\Search\Extensions;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\IExtendsModel;

/**
 * Whether an extender's `extension` document and mapping agree.
 *
 * {@see IExtendsModel::searchableExtension()} writes the values and {@see IExtendsModel::searchableExtensionMapping()}
 * declares their types, in two methods that drift apart unnoticed: a key written but not declared is indexed with the
 * type the engine guesses from the first value, and is neither filterable nor translatable (XS10); a key declared but
 * not written is a field no document fills. `type` belongs to the host, which writes the alias there: an extender
 * writing it would overwrite the alias. Spec `docs/superpowers/specs/2026-11-17-extended-entity-search-design.md`,
 * XS10 and XS16.
 *
 * The document is read from a new instance of the extender, so only its keys count, never its values. A host module
 * runs this over every registered extender in its test suite, next to the XS16 composition.
 */
final class ExtensionDocumentCheck
{
    /**
     * The disagreements of one extender, each a sentence naming the extender and the field. Empty when they agree.
     *
     * @param  class-string<Model&IExtendsModel>  $extender_class
     * @return list<string>
     */
    public static function violations(string $extender_class): array
    {
        /** @var Model&IExtendsModel $extender */
        $extender = new $extender_class();
        $written = array_keys($extender->searchableExtension());
        $declared = array_keys($extender->searchableExtensionMapping());
        $violations = [];

        foreach (array_diff($written, $declared) as $field) {
            $violations[] = sprintf('%s writes `extension.%s` but searchableExtensionMapping() does not declare it: declare its type.', $extender_class, $field);
        }

        foreach (array_diff($declared, $written) as $field) {
            $violations[] = sprintf('%s declares `extension.%s` but searchableExtension() does not write it: write it or drop the declaration.', $extender_class, $field);
        }

        if (in_array('type', [...$written, ...$declared], true)) {
            $violations[] = sprintf('%s uses `extension.type`, which holds the alias the host writes: rename the field.', $extender_class);
        }

        return $violations;
    }
}
