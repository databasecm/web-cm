<?php

use App\Enums\Bidang;
use App\Exceptions\MediaException;
use App\Models\Design;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
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

function mediaUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

/** A design on a fresh project with a real stored image file. */
function designWithFile(MediaService $media): Design
{
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $key = $media->store(new Design, UploadedFile::fake()->image('desain.png'));

    return Design::factory()->create(['project_id' => $project->id, 'file' => $key]);
}

// ---------------------------------------------------------------------------
// Store + server-side validation (type & size)
// ---------------------------------------------------------------------------

it('stores a valid upload and returns a disk key', function () {
    $key = $this->media->store(new Design, UploadedFile::fake()->image('desain.png'));

    expect($key)->toStartWith('designs/')
        ->and(Storage::disk('media')->exists($key))->toBeTrue();
});

it('rejects an unsupported file type server-side', function () {
    expect(fn () => $this->media->store(new Design, UploadedFile::fake()->create('malware.bin', 10)))
        ->toThrow(MediaException::class);

    expect(Storage::disk('media')->allFiles())->toBeEmpty();
});

it('rejects an oversize file server-side', function () {
    // Designs allow images up to 5120 KB; 6000 KB is over.
    expect(fn () => $this->media->store(new Design, UploadedFile::fake()->image('huge.png')->size(6000)))
        ->toThrow(MediaException::class);

    expect(Storage::disk('media')->allFiles())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Serve is signed AND policy-checked — no naked/guessed URL works
// ---------------------------------------------------------------------------

it('serves a media file over a signed URL to an authorized user', function () {
    $design = designWithFile($this->media);

    $this->actingAs(mediaUser('owner')); // overseer may view any project's design
    $url = $this->media->temporaryUrl($design);

    $this->get($url)->assertOk();
});

it('denies an unauthorized user even with a valid signature (policy layer)', function () {
    $design = designWithFile($this->media);

    // A consumer who does not own the project cannot view its design.
    $this->actingAs(mediaUser('konsumen'));
    $url = $this->media->temporaryUrl($design);

    $this->get($url)->assertForbidden();
});

it('rejects an expired signed URL (freshness layer)', function () {
    $design = designWithFile($this->media);

    $this->actingAs(mediaUser('owner'));
    $url = $this->media->temporaryUrl($design); // TTL 5 min (config default)

    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();
});

it('rejects a naked (unsigned) media URL', function () {
    $design = designWithFile($this->media);

    $this->actingAs(mediaUser('owner'));
    // The route without a signature — a guessed URL — is refused.
    $this->get(route('media.show', ['type' => 'design', 'id' => $design->id]))
        ->assertForbidden();
});

it('requires authentication to reach the media route', function () {
    $design = designWithFile($this->media);
    $url = $this->media->temporaryUrl($design); // valid signature, but no auth

    // A guest is unauthenticated even with a fresh signature (JSON = 401, no redirect).
    $this->getJson($url)->assertUnauthorized();
});
