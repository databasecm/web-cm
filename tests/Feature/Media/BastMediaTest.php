<?php

use App\Enums\Bidang;
use App\Exceptions\MediaException;
use App\Models\Bast;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\BastPdf;
use App\Services\MediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    $this->media = app(MediaService::class);
});

function bmUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

/** A real minimal PDF upload (so the server-side MIME guess is application/pdf). */
function bmFakePdf(string $name = 'bast.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}

// ---------------------------------------------------------------------------
// Attachment upload — PDF only, server-side
// ---------------------------------------------------------------------------

it('stores a valid PDF BAST attachment', function () {
    $key = $this->media->store(new Bast, bmFakePdf());

    expect($key)->toStartWith('bast/')
        ->and(Storage::disk('media')->exists($key))->toBeTrue();
});

it('rejects a non-PDF BAST attachment server-side', function () {
    expect(fn () => $this->media->store(new Bast, UploadedFile::fake()->image('foto.png')))
        ->toThrow(MediaException::class);

    expect(Storage::disk('media')->allFiles())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Serving the attachment honours BastPolicy::view (signature alone is not enough)
// ---------------------------------------------------------------------------

it('serves the BAST attachment only when the view policy passes', function () {
    $project = Project::factory()->create();
    $bast = Bast::factory()->create([
        'project_id' => $project->id,
        'file' => $this->media->store(new Bast, bmFakePdf()),
    ]);

    // Overseer may view the project's BAST → attachment served.
    $this->actingAs(bmUser('owner'));
    $this->get($this->media->temporaryUrl($bast))->assertOk();

    // A consumer who does not own the project cannot — even with a valid signature.
    $this->actingAs(bmUser('konsumen'));
    $this->get($this->media->temporaryUrl($bast))->assertForbidden();
});

// ---------------------------------------------------------------------------
// The GENERATED BAST PDF (downloadPdf) is a separate artefact, unaffected
// ---------------------------------------------------------------------------

it('keeps the generated BAST PDF separate from the uploaded attachment', function () {
    $owner = bmUser('owner');
    $project = Project::factory()->create();

    // Draft BAST with an attachment: the attachment is viewable, but the
    // generated PDF is NOT available yet (downloadPdf needs a signed BAST).
    $draft = Bast::factory()->create([
        'project_id' => $project->id,
        'file' => $this->media->store(new Bast, bmFakePdf()),
    ]);
    expect($owner->can('view', $draft))->toBeTrue()            // attachment (media, 'view')
        ->and($owner->can('downloadPdf', $draft))->toBeFalse(); // generated PDF gated on signed

    // Once signed, the generated PDF renders — independent of any attachment.
    // (A separate project — one BAST per project.)
    $signed = Bast::factory()->signed()->create([
        'project_id' => Project::factory()->create()->id,
    ]);
    expect($owner->can('downloadPdf', $signed))->toBeTrue()
        ->and(strlen(app(BastPdf::class)->make($signed)->output()))->toBeGreaterThan(0);
});
