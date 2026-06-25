<?php

namespace App\Filament\Resources;

use App\Enums\Bidang;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Account management UI. Authorization is delegated to {@see UserPolicy}
 * (Filament consults it for view/create/edit/delete), so the menu and per-row
 * actions only appear for actors with the right (Owner, Direktur, Manager);
 * Mitra/Finance/HR/Mandor/Konsumen never see it. Delete is automatically hidden
 * for protected Owners and for the actor's own row by the policy.
 *
 * The role/bidang the actor may assign is constrained both in the form (options)
 * and server-side via the shared `assign-account` gate from Task 5.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Akun';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Akun';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Forms\Components\TextInput::make('password')
                ->label('Kata Sandi')
                ->password()
                ->revealable()
                ->minLength(8)
                // Required only when creating; on edit, blank keeps the current one.
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): ?string => $operation === 'edit'
                    ? 'Kosongkan jika tidak ingin mengubah kata sandi.'
                    : null),

            Forms\Components\Select::make('role_id')
                ->label('Peran')
                ->options(fn (): array => static::assignableRoleOptions())
                ->required()
                ->live()
                ->native(false),

            Forms\Components\Select::make('bidang')
                ->label('Bidang')
                ->options(fn (): array => static::bidangOptions())
                ->visible(fn (Get $get): bool => static::roleRequiresBidang($get('role_id')))
                ->required(fn (Get $get): bool => static::roleRequiresBidang($get('role_id')))
                // A bidang-scoped actor (Manager) is locked to its own unit.
                ->default(fn (): ?string => auth()->user()?->isBidangScoped()
                    ? auth()->user()->bidang?->value
                    : null)
                ->disabled(fn (): bool => (bool) auth()->user()?->isBidangScoped())
                ->dehydrated(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role.name')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => $state ? ucwords(str_replace('_', ' ', $state)) : null),
                Tables\Columns\TextColumn::make('bidang')
                    ->label('Bidang')
                    ->badge()
                    ->formatStateUsing(fn (?Bidang $state): ?string => $state?->label()),
                Tables\Columns\IconColumn::make('is_protected')
                    ->label('Terlindungi')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Normalize the bidang shape and authorize the role/bidang assignment via
     * the shared `assign-account` gate (Task 5). Surfaces violations as form
     * validation so the page shows the error inline. Called by the create/edit
     * pages so both paths enforce the same rules as the API Form Requests.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAndAuthorize(array $data): array
    {
        $role = isset($data['role_id']) ? Role::find($data['role_id']) : null;

        // Roles that must not carry a bidang never persist one.
        if ($role !== null && ! $role->requiresBidang()) {
            $data['bidang'] = null;
        }

        // Keys are prefixed with the form statePath ('data.') so the error
        // attaches to the Filament field that raised it.
        if ($role !== null && $role->requiresBidang() && blank($data['bidang'] ?? null)) {
            throw ValidationException::withMessages([
                'data.bidang' => 'Bidang wajib diisi untuk peran manager dan mandor.',
            ]);
        }

        if (! Gate::allows('assign-account', [$role, $data['bidang'] ?? null])) {
            throw ValidationException::withMessages([
                'data.role_id' => 'Anda tidak berhak menetapkan peran atau bidang ini.',
            ]);
        }

        return $data;
    }

    /**
     * Role options the current actor may assign: every role strictly below its
     * own level, mirroring the assign gate.
     *
     * @return array<int, string>
     */
    protected static function assignableRoleOptions(): array
    {
        return auth()->user()
            ?->assignableRoles()
            ->mapWithKeys(fn (Role $role): array => [
                $role->id => ucwords(str_replace('_', ' ', $role->name)),
            ])
            ->all() ?? [];
    }

    /**
     * @return array<string, string>
     */
    protected static function bidangOptions(): array
    {
        $actor = auth()->user();

        // A bidang-scoped actor may only place accounts in its own unit.
        if ($actor?->isBidangScoped() && $actor->bidang !== null) {
            return [$actor->bidang->value => $actor->bidang->label()];
        }

        return collect(Bidang::cases())
            ->mapWithKeys(fn (Bidang $bidang): array => [$bidang->value => $bidang->label()])
            ->all();
    }

    protected static function roleRequiresBidang(mixed $roleId): bool
    {
        return $roleId !== null && (bool) Role::find($roleId)?->requiresBidang();
    }
}
