<?php

use App\Enums\Bidang;
use App\Enums\ReportMediaType;
use App\Models\DailyReport;
use App\Models\Project;
use App\Models\ReportMedia;
use App\Models\Role;
use App\Models\User;
use App\Services\MediaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
});

function rmUser(string $role, ?Bidang $bidang = null): User
{
    return User::factory()->create(['role_id' => Role::where('name', $role)->value('id'), 'bidang' => $bidang]);
}

function rmReport(Bidang $bidang, User $mandor): DailyReport
{
    $project = Project::factory()->inBidang($bidang)->create();

    return DailyReport::factory()->create(['project_id' => $project->id, 'mandor_id' => $mandor->id]);
}

/** A fake MP4 upload with real bytes (server-side MIME guess = video/mp4). */
function rmFakeVideo(): UploadedFile
{
    // ftyp box marking an ISO Base Media (MP4) file — finfo detects video/mp4.
    return UploadedFile::fake()->createWithContent('klip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
}

// ---------------------------------------------------------------------------
// Upload — type derived from MIME (not the client), photo & video
// ---------------------------------------------------------------------------

it('lets a Mandor upload a photo and a video to its own report, typed from MIME', function () {
    $mandor = rmUser('mandor', Bidang::Cufid);
    $report = rmReport(Bidang::Cufid, $mandor);
    Sanctum::actingAs($mandor);

    $this->post("/api/v1/mandor/daily-reports/{$report->id}/media", [
        'file' => UploadedFile::fake()->image('foto.jpg'),
        'caption' => 'progres cor',
    ])->assertCreated()->assertJsonPath('data.type', 'photo');

    $this->post("/api/v1/mandor/daily-reports/{$report->id}/media", [
        'file' => rmFakeVideo(),
    ])->assertCreated()->assertJsonPath('data.type', 'video');

    $media = $report->media()->get();
    expect($media)->toHaveCount(2)
        ->and($media->pluck('type')->all())->toEqual([ReportMediaType::Photo, ReportMediaType::Video])
        ->and($media->every(fn (ReportMedia $m): bool => Storage::disk('media')->exists($m->file)))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Authorization & validation
// ---------------------------------------------------------------------------

it('forbids a Mandor from uploading to another bidang report', function () {
    $report = rmReport(Bidang::Cufid, rmUser('mandor', Bidang::Cufid));

    Sanctum::actingAs(rmUser('mandor', Bidang::Cc)); // other bidang
    $this->post("/api/v1/mandor/daily-reports/{$report->id}/media", [
        'file' => UploadedFile::fake()->image('foto.jpg'),
    ])->assertForbidden();

    expect(ReportMedia::count())->toBe(0);
});

it('rejects an unsupported media type server-side', function () {
    $mandor = rmUser('mandor', Bidang::Cufid);
    $report = rmReport(Bidang::Cufid, $mandor);
    Sanctum::actingAs($mandor);

    $this->post("/api/v1/mandor/daily-reports/{$report->id}/media", [
        'file' => UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\n%%EOF"), // pdf not allowed for report media
    ])->assertStatus(422);

    expect(ReportMedia::count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBeEmpty();
});

it('rejects an oversize image server-side', function () {
    $mandor = rmUser('mandor', Bidang::Cufid);
    $report = rmReport(Bidang::Cufid, $mandor);
    Sanctum::actingAs($mandor);

    $this->post("/api/v1/mandor/daily-reports/{$report->id}/media", [
        'file' => UploadedFile::fake()->image('huge.jpg')->size(6000), // > 5120 KB image ceiling
    ])->assertStatus(422);

    expect(ReportMedia::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Serve — follows the parent report's view policy
// ---------------------------------------------------------------------------

it('serves report media only when the report view policy passes', function () {
    $mandor = rmUser('mandor', Bidang::Cufid);
    $project = Project::factory()->inBidang(Bidang::Cufid)->create();
    $report = DailyReport::factory()->create(['project_id' => $project->id, 'mandor_id' => $mandor->id]);
    $media = $report->media()->create([
        'type' => ReportMediaType::Photo,
        'file' => app(MediaService::class)->store(new ReportMedia, UploadedFile::fake()->image('foto.jpg')),
    ]);
    $url = app(MediaService::class)->temporaryUrl($media);

    // Owning consumer of the project may view the report media.
    $konsumen = rmUser('konsumen');
    $project->update(['konsumen_id' => $konsumen->id]);
    $this->actingAs($konsumen)->get($url)->assertOk();

    // A Mandor of another bidang cannot — even with a valid signature.
    $this->actingAs(rmUser('mandor', Bidang::Cc))->get($url)->assertForbidden();
});

it('lets the project financing bank view report media read-only', function () {
    $mandor = rmUser('mandor', Bidang::Cufid);
    $bank = rmUser('mitra_pembiayaan');
    $project = Project::factory()->inBidang(Bidang::Cufid)->create(['bank_mitra_id' => $bank->id]);
    $report = DailyReport::factory()->create(['project_id' => $project->id, 'mandor_id' => $mandor->id]);
    $media = $report->media()->create([
        'type' => ReportMediaType::Photo,
        'file' => app(MediaService::class)->store(new ReportMedia, UploadedFile::fake()->image('foto.jpg')),
    ]);

    $this->actingAs($bank)->get(app(MediaService::class)->temporaryUrl($media))->assertOk();
});
