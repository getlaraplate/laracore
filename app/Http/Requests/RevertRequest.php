<?php

declare(strict_types=1);

namespace Modules\Core\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Core\Casts\RevertRequestData;
use Modules\Core\Enums\RevertMode;
use Override;

/**
 * A revert of one record to a revision (D56, D62): the record's key, the revision, the revision the client read, and
 * optionally the mode and whether to skip a referenced record that no longer exists.
 *
 * @property int $revision
 * @property int $expected_revision
 */
final class RevertRequest extends DetailRequest
{
    #[Override]
    public function rules(): array
    {
        return parent::rules() + [
            'revision' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'mode' => ['sometimes', 'nullable', Rule::enum(RevertMode::class)],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    #[Override]
    public function parsed(): RevertRequestData
    {
        /** @phpstan-ignore method.notFound */
        return new RevertRequestData($this, $this->resolveMainEntity(), $this->validated(), $this->primaryKey, $this->input('module'));
    }
}
