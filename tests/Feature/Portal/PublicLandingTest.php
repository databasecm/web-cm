<?php

use App\Livewire\Public\Landing;
use App\Models\Consultation;
use App\Models\ConsultationMessage;
use App\Models\Project;
use App\Services\GuestConsultationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Redis::connection()->flushdb();
});

// ---------------------------------------------------------------------------
// 1) Renders publicly (no login) and shows the company + all five units
// ---------------------------------------------------------------------------

it('renders the public landing without login, showing all five units', function () {
    $this->get(route('landing'))
        ->assertOk()
        ->assertSee('CV. Cimandiri')
        ->assertSee('CuFID')
        ->assertSee('Custom Construction')
        ->assertSee('SolIT')
        ->assertSee('BIRU GIS')
        ->assertSee('Cimandiri (CM)'); // the parent unit
});

// ---------------------------------------------------------------------------
// 2) The CTA into the consumer portal is present
// ---------------------------------------------------------------------------

it('links to the consumer portal login', function () {
    $this->get(route('landing'))
        ->assertOk()
        ->assertSee(route('portal.login'), escape: false);
});

// ---------------------------------------------------------------------------
// 3) Guest consultation → stored via GuestConsultationStore (Redis), NOT the DB
// ---------------------------------------------------------------------------

it('starts a guest consultation in Redis without touching the database', function () {
    $component = Livewire::test(Landing::class)
        ->set('consultBidang', 'cufid')
        ->set('consultMessage', 'Halo, saya ingin konsultasi furniture.')
        ->call('startConsultation')
        ->assertHasNoErrors();

    $token = $component->get('token');

    // A live Redis session exists, routed to the chosen bidang…
    expect($token)->not->toBeNull()
        ->and(app(GuestConsultationStore::class)->exists($token))->toBeTrue()
        ->and(app(GuestConsultationStore::class)->meta($token)['bidang'])->toBe('cufid');

    // …and the ephemeral guarantee (1B) holds: nothing is persisted.
    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});

it('appends and polls the guest thread, still writing nothing to the database', function () {
    $store = app(GuestConsultationStore::class);

    $component = Livewire::test(Landing::class)
        ->set('consultBidang', 'cc')
        ->set('consultMessage', 'Pesan pertama.')
        ->call('startConsultation')
        ->set('consultMessage', 'Pesan kedua.')
        ->call('sendReply')
        ->assertHasNoErrors();

    $token = $component->get('token');

    // A Manager reply lands in Redis; the guest poll picks it up.
    $store->appendManagerReply($token, 'Terima kasih, kami bantu.');
    $component->call('poll');

    $messages = collect($component->get('messages'))->pluck('message');
    expect($messages)->toContain('Pesan pertama.')
        ->and($messages)->toContain('Pesan kedua.')
        ->and($messages)->toContain('Terima kasih, kami bantu.');

    expect(Consultation::count())->toBe(0)
        ->and(ConsultationMessage::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 4) Validation — a valid bidang and a message are required
// ---------------------------------------------------------------------------

it('validates the consultation bidang and message', function () {
    Livewire::test(Landing::class)
        ->set('consultBidang', '')
        ->set('consultMessage', '')
        ->call('startConsultation')
        ->assertHasErrors(['consultBidang', 'consultMessage']);

    Livewire::test(Landing::class)
        ->set('consultBidang', 'tidak-ada')
        ->set('consultMessage', 'halo')
        ->call('startConsultation')
        ->assertHasErrors('consultBidang');
});

// ---------------------------------------------------------------------------
// 5) No leakage — the public page never renders project/consumer data
// ---------------------------------------------------------------------------

it('never exposes project or consumer data on the public page', function () {
    // Even with a real project + owning consumer in the database, none of it
    // reaches the landing (the page issues no project/consumer query).
    $project = Project::factory()->create(['title' => 'Proyek Rahasia Konsumen']);
    $consumerEmail = $project->konsumen->email;

    $this->get(route('landing'))
        ->assertOk()
        ->assertDontSee('Proyek Rahasia Konsumen')
        ->assertDontSee($consumerEmail);
});

// ---------------------------------------------------------------------------
// 6) CM is a profile-only unit — it is not a consultable bidang
// ---------------------------------------------------------------------------

it('offers only the four real bidang in the consultation selector', function () {
    // The four consultable units are options; the parent CM is profile-only and
    // has no bidang value, so it cannot be selected as a consultation target.
    Livewire::test(Landing::class)
        ->assertSeeHtml('value="cufid"')
        ->assertSeeHtml('value="cc"')
        ->assertSeeHtml('value="solit"')
        ->assertSeeHtml('value="birugis"')
        ->assertDontSeeHtml('value="cm"');
});
