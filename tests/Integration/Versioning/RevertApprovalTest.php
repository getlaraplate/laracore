<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Approvals\Operation;
use Modules\Core\Approvals\PendingDeletionLock;
use Modules\Core\Enums\RevertMode;
use Modules\Core\Enums\VersionSetKind;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Services\ModificationVoteService;
use Modules\Core\Tests\Stubs\Versioning\VersionedApprovalDocument;
use Modules\Core\Tests\Support\HttpContext;
use Modules\Core\Versioning\Data\RevertReport;
use Modules\Core\Versioning\Exceptions\RevisionConflictException;

/*
 * A revert the writer may not run alone becomes one request (D26, D62): nothing is reverted until it is approved, the
 * approval runs it with the expected revision the request carries, and its own writes ask nothing more.
 */

beforeEach(function (): void {
    config()->set('versionable.version_model', Version::class);

    Schema::create(VersionedApprovalDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });
    VersionedApprovalDocument::$operations = null;

    // Written by the console, which needs no approval.
    $this->document = VersionedApprovalDocument::query()->create(['title' => 'First']);
    $this->target = (int) $this->document->currentRevision();
    $this->document->update(['title' => 'Second']);
    $this->expected = (int) $this->document->currentRevision();

    $this->superadmin = User::factory()->create();
    $this->superadmin->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
});

afterEach(function (): void {
    VersionedApprovalDocument::$operations = null;
    Schema::dropIfExists(VersionedApprovalDocument::TABLE);
});

function revert_as_writer(object $test): RevertReport
{
    HttpContext::pretendHttpRequest();
    Auth::login(User::factory()->create());

    return $test->document->revertToRevision($test->target, $test->expected, RevertMode::Scalar);
}

it('turns the revert of a writer who needs approval into one request, reverting nothing', function (): void {
    $report = revert_as_writer($this);

    expect($report->isPending())->toBeTrue()
        ->and($report->revision)->toBeNull()
        ->and($report->pendingRequest?->operation)->toBe(Operation::Revert)
        ->and($report->pendingRequest?->modifications)->toBe(['revision' => $this->target, 'expected_revision' => $this->expected, 'mode' => 'scalar', 'force' => false])
        ->and($this->document->fresh()->title)->toBe('Second')
        ->and(VersionSet::query()->where('kind', VersionSetKind::Revert)->count())->toBe(0);
});

it('joins the pending request when the same revert is asked again', function (): void {
    revert_as_writer($this);
    revert_as_writer($this);

    expect($this->document->modifications()->activeOnly()->where('operation', Operation::Revert->value)->count())->toBe(1);
});

it('reverts the record when the request is approved, as one revert set and no second request', function (): void {
    $request = revert_as_writer($this)->pendingRequest;

    expect(app(ModificationVoteService::class)->cast($this->superadmin, $request, true))->toBeTrue();

    expect($this->document->fresh()->title)->toBe('First')
        ->and(VersionSet::query()->where('kind', VersionSetKind::Revert)->count())->toBe(1)
        ->and($request->fresh()->active)->toBeFalse()
        ->and($this->document->modifications()->activeOnly()->count())->toBe(0);
});

it('refuses the approval with a conflict when the record changed since the request, which stays pending', function (): void {
    $request = revert_as_writer($this);
    Auth::login($this->superadmin);
    VersionedApprovalDocument::query()->findOrFail($this->document->getKey())->update(['title' => 'Third']);

    expect(fn () => app(ModificationVoteService::class)->cast($this->superadmin, $request->pendingRequest, true))
        ->toThrow(RevisionConflictException::class);

    expect($this->document->fresh()->title)->toBe('Third')
        ->and(Modification::query()->findOrFail($request->pendingRequest->getKey())->active)->toBeTrue()
        ->and(Modification::query()->findOrFail($request->pendingRequest->getKey())->approvals()->count())->toBe(0);
});

it('makes no request for a revert that could never be applied: a record whose deletion waits for approval', function (): void {
    HttpContext::pretendHttpRequest();
    Auth::login(User::factory()->create());
    $this->document->delete();

    expect(fn () => $this->document->revertToRevision($this->target, $this->expected, RevertMode::Scalar))
        ->toThrow(PendingDeletionLock::class)
        ->and($this->document->modifications()->activeOnly()->where('operation', Operation::Revert->value)->count())->toBe(0);

    Auth::login($this->superadmin);

    expect(fn () => $this->document->revertToRevision($this->target, $this->expected, RevertMode::Scalar))
        ->toThrow(PendingDeletionLock::class)
        ->and($this->document->fresh()->title)->toBe('Second');
});

it('reverts at once for a superadmin, and for a model that does not ask approval of a revert', function (): void {
    HttpContext::pretendHttpRequest();
    Auth::login($this->superadmin);

    $report = $this->document->revertToRevision($this->target, $this->expected, RevertMode::Scalar);

    expect($report->isPending())->toBeFalse()
        ->and($this->document->fresh()->title)->toBe('First');

    VersionedApprovalDocument::$operations = [Operation::Create];
    $other = VersionedApprovalDocument::query()->create(['title' => 'Draft']);
    $created = (int) $other->currentRevision();
    $other->update(['title' => 'Edited']);
    Auth::login(User::factory()->create());

    expect($other->revertToRevision($created, (int) $other->currentRevision(), RevertMode::Scalar)->isPending())->toBeFalse()
        ->and($other->fresh()->title)->toBe('Draft');
});

it('asks approval of a revert on a model whose approval operations predate it and name updates', function (): void {
    VersionedApprovalDocument::$operations = [Operation::Update];

    expect(revert_as_writer($this)->isPending())->toBeTrue()
        ->and($this->document->fresh()->title)->toBe('Second');
});


it('keeps a different revert of the same revision as a request of its own', function (): void {
    revert_as_writer($this);
    $this->document->revertToRevision($this->target, $this->expected, RevertMode::Scalar, force: true);

    expect($this->document->modifications()->activeOnly()->where('operation', Operation::Revert->value)->count())->toBe(2);
});
