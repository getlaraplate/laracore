<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Approvals\PendingDeletionLock;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\ModificationVoteService;
use Modules\Core\Tests\Stubs\Approvals\HiddenWhilePendingDeletionModel;
use Modules\Core\Tests\Stubs\Approvals\SoftDeletableApprovalModel;
use Modules\Core\Tests\Support\HttpContext;

beforeEach(function (): void {
    Schema::create('approvals_soft_stub', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->boolean('is_deleted')->storedAs('deleted_at IS NOT NULL');
    });
    SoftDeletableApprovalModel::$operations = null;
    SoftDeletableApprovalModel::$approvers = null;
    SoftDeletableApprovalModel::$writable = [];
    $this->record = SoftDeletableApprovalModel::query()->create(['name' => 'kept']);
    HttpContext::pretendHttpRequest();
    $this->author = User::factory()->create();
    $this->approver = User::factory()->create();
    $this->approver->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
});

afterEach(fn () => Schema::dropIfExists('approvals_soft_stub'));

it('refuses to save a record whose deletion is pending', function (): void {
    Auth::login($this->author);
    $this->record->delete();

    $this->record->name = 'edited';

    expect(fn () => $this->record->save())->toThrow(PendingDeletionLock::class);
});

it('still saves the attributes the model declares writable', function (): void {
    SoftDeletableApprovalModel::$writable = ['name'];
    Auth::login($this->author);
    $this->record->delete();
    Auth::login($this->approver);

    $this->record->name = 'system write';
    $this->record->save();

    expect($this->record->fresh()->name)->toBe('system write');
});

it('blocks the same attribute when the model does not declare it writable', function (): void {
    SoftDeletableApprovalModel::$writable = [];
    Auth::login($this->author);
    $this->record->delete();
    Auth::login($this->approver);

    $this->record->name = 'system write';

    expect(fn () => $this->record->save())->toThrow(PendingDeletionLock::class);
});

it('hides a record whose deletion is pending from users who cannot decide on it', function (): void {
    Auth::login($this->author);
    $hidden = HiddenWhilePendingDeletionModel::query()->find($this->record->id);
    $hidden->delete();

    expect(HiddenWhilePendingDeletionModel::query()->whereKey($this->record->id)->exists())->toBeFalse();

    Auth::login($this->approver);

    expect(HiddenWhilePendingDeletionModel::query()->whereKey($this->record->id)->exists())->toBeTrue();
});

it('shows the record again once the deletion is rejected', function (): void {
    Auth::login($this->author);
    $hidden = HiddenWhilePendingDeletionModel::query()->find($this->record->id);
    $hidden->delete();
    resolve(ModificationVoteService::class)->cast($this->approver, $hidden->pendingModification(), false);

    expect(HiddenWhilePendingDeletionModel::query()->whereKey($this->record->id)->exists())->toBeTrue();
});

it('hides a pending deletion from an anonymous visitor of an HTTP request', function (): void {
    Auth::login($this->author);
    $hidden = HiddenWhilePendingDeletionModel::query()->find($this->record->id);
    $hidden->delete();
    Auth::logout();

    // Nobody signed in, inside a request: a visitor read before the anonymous account was put on the guard.
    expect(HiddenWhilePendingDeletionModel::query()->whereKey($this->record->id)->exists())->toBeFalse();
});

it('does not hide a pending deletion from work that runs in the console', function (): void {
    Auth::login($this->author);
    $hidden = HiddenWhilePendingDeletionModel::query()->find($this->record->id);
    $hidden->delete();
    Auth::logout();

    // Back to the real application, which runs in the console: commands, queue workers, search indexing.
    App::swap(app());

    expect(HiddenWhilePendingDeletionModel::query()->whereKey($this->record->id)->exists())->toBeTrue();
});
