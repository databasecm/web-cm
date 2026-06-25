<?php

use App\Enums\Bidang;
use App\Enums\ConsultationStatus;
use App\Enums\SenderType;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('casts bidang, status and is_guest', function () {
    $consultation = Consultation::factory()
        ->inBidang(Bidang::Solit)
        ->status(ConsultationStatus::Deal)
        ->create();

    expect($consultation->bidang)->toBe(Bidang::Solit)
        ->and($consultation->status)->toBe(ConsultationStatus::Deal)
        ->and($consultation->is_guest)->toBeFalse();
});

it('defaults to an open, non-guest, unclaimed thread', function () {
    $consultation = Consultation::factory()->create();

    expect($consultation->status)->toBe(ConsultationStatus::Open)
        ->and($consultation->is_guest)->toBeFalse()
        ->and($consultation->manager_id)->toBeNull()
        ->and($consultation->isClosed())->toBeFalse();
});

it('relates a thread to its consumer, manager and messages', function () {
    $konsumen = User::factory()->create([
        'role_id' => Role::where('name', 'konsumen')->value('id'),
    ]);
    $manager = User::factory()->create([
        'role_id' => Role::where('name', 'manager')->value('id'),
        'bidang' => Bidang::Cufid,
    ]);

    $consultation = Consultation::factory()
        ->ownedBy($konsumen)
        ->claimedBy($manager)
        ->create();

    ConsultationMessage::factory()->count(3)->for($consultation)->create();

    expect($consultation->konsumen->is($konsumen))->toBeTrue()
        ->and($consultation->manager->is($manager))->toBeTrue()
        ->and($consultation->messages)->toHaveCount(3);
});

it('casts a message sender_type and links back to its thread', function () {
    $message = ConsultationMessage::factory()->fromManager()->create();

    expect($message->sender_type)->toBe(SenderType::Manager)
        ->and($message->consultation)->toBeInstanceOf(Consultation::class);
});

it('cascades message deletion when a thread is deleted', function () {
    $consultation = Consultation::factory()->create();
    ConsultationMessage::factory()->count(2)->for($consultation)->create();

    $consultation->delete();

    expect(ConsultationMessage::where('consultation_id', $consultation->id)->count())->toBe(0);
});
