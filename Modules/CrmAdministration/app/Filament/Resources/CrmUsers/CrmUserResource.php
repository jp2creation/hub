<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmUsers;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmUser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Modules\CrmAdministration\Filament\Resources\CrmUsers\Pages\ManageCrmUsers;
use Modules\CrmAdministration\Services\AdminAccountService;
use Spatie\Permission\Models\Role;
use UnitEnum;

class CrmUserResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Utilisateurs HUB';

    protected static ?string $modelLabel = 'utilisateur HUB';

    protected static ?string $pluralModelLabel = 'utilisateurs HUB';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compte')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->required()
                            ->maxLength(160),
                        TextInput::make('first_name')
                            ->label('Prénom')
                            ->maxLength(80),
                        TextInput::make('last_name')
                            ->label('Nom de famille')
                            ->maxLength(80),
                        TextInput::make('email')
                            ->label('E-mail HUB')
                            ->email()
                            ->maxLength(190),
                        TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(40),
                        Select::make('user_id')
                            ->label('Compte Laravel')
                            ->relationship('account', 'email')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->unique(ignoreRecord: true),
                        Select::make('role')
                            ->label('Profil')
                            ->options(CrmUser::roleOptions())
                            ->required()
                            ->default('user'),
                        Toggle::make('active')
                            ->label('Compte actif')
                            ->default(true),
                        TextInput::make('password')
                            ->label('Nouveau mot de passe')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->nullable()
                            ->visible(fn (string $operation): bool => $operation === 'edit' && self::canManageHubUsers())
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->rule(Password::min(max(12, (int) config('crm.admin_password.min_length', 12)))
                                ->mixedCase()
                                ->numbers()
                                ->symbols())
                            ->maxLength(255)
                            ->helperText('Laisser vide pour conserver le mot de passe actuel.'),
                        TextInput::make('password_confirmation')
                            ->label('Confirmation du mot de passe')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->nullable()
                            ->visible(fn (string $operation): bool => $operation === 'edit' && self::canManageHubUsers())
                            ->requiredWith('password')
                            ->same('password')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                    ])
                    ->columns(3),
                Section::make('Acces HUB')
                    ->schema([
                        CheckboxList::make('sites')
                            ->label('Sites autorises')
                            ->relationship('sites', 'name')
                            ->columns(2)
                            ->bulkToggleable()
                            ->extraAttributes([
                                'class' => 'crm-scrollable-checkbox-list-options',
                            ], merge: true),
                        CheckboxList::make('modules')
                            ->label('Modules autorises')
                            ->relationship('modules', 'name')
                            ->columns(2)
                            ->bulkToggleable()
                            ->extraAttributes([
                                'class' => 'crm-scrollable-checkbox-list-options',
                            ], merge: true),
                        CheckboxList::make('permissions')
                            ->label('Permissions')
                            ->relationship('permissions', 'label')
                            ->columns(2)
                            ->bulkToggleable()
                            ->extraAttributes([
                                'class' => 'crm-scrollable-checkbox-list-options',
                            ], merge: true),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Nom'),
                TextEntry::make('first_name')->label('Prénom')->placeholder('Non renseigné'),
                TextEntry::make('last_name')->label('Nom de famille')->placeholder('Non renseigné'),
                TextEntry::make('email')->label('E-mail HUB')->placeholder('Non renseigné'),
                TextEntry::make('phone')->label('Téléphone')->placeholder('Non renseigné'),
                TextEntry::make('account.email')->label('Compte Laravel')->placeholder('Non rattache'),
                TextEntry::make('role')->label('Profil')->badge(),
                IconEntry::make('active')->label('Actif')->boolean(),
                TextEntry::make('sites.name')->label('Sites')->badge(),
                TextEntry::make('modules.name')->label('Modules')->badge(),
                TextEntry::make('permissions.label')->label('Permissions')->badge()->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['account:id,email']))
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->placeholder('Non renseigné')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Profil')
                    ->badge()
                    ->sortable(),
                TextColumn::make('account.email')
                    ->label('Compte Laravel')
                    ->placeholder('Non rattache')
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sites_count')
                    ->label('Sites')
                    ->counts('sites')
                    ->sortable(),
                TextColumn::make('modules_count')
                    ->label('Modules')
                    ->counts('modules')
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label('Droits')
                    ->counts('permissions')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Profil')
                    ->options(CrmUser::roleOptions()),
                TernaryFilter::make('active')
                    ->label('Actif')
                    ->trueLabel('Actifs')
                    ->falseLabel('Masques'),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('createLaravelAccount')
                    ->label('Compte Laravel')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('primary')
                    ->authorize('update')
                    ->visible(fn (CrmUser $record): bool => blank($record->user_id))
                    ->modalHeading('Creer un compte Laravel')
                    ->modalDescription(fn (CrmUser $record): string => "Utilisateur HUB : {$record->name}")
                    ->modalSubmitActionLabel('Creer et rattacher')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom')
                            ->default(fn (CrmUser $record): string => $record->name)
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email')
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label('Mot de passe initial')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(12)
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label('Confirmation')
                            ->password()
                            ->revealable()
                            ->required()
                            ->same('password')
                            ->maxLength(255),
                    ])
                    ->action(function (CrmUser $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            $record->refresh();

                            if (filled($record->user_id)) {
                                return;
                            }

                            $account = User::query()->create([
                                'name' => $data['name'],
                                'email' => $data['email'],
                                'password' => $data['password'],
                            ]);

                            $role = Role::firstOrCreate([
                                'name' => 'user',
                                'guard_name' => 'web',
                            ]);

                            $account->assignRole($role);

                            $record->forceFill([
                                'user_id' => $account->id,
                                'email' => $record->email ?: $account->email,
                            ])->save();
                        });
                    })
                    ->successNotificationTitle('Compte Laravel cree et rattache'),
                ViewAction::make(),
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->before(function (EditAction $action, CrmUser $record): void {
                        $password = (string) ($action->getRawData()['password'] ?? '');

                        if ($password === '') {
                            return;
                        }

                        if (! self::canManageHubUsers()) {
                            throw ValidationException::withMessages([
                                'password' => 'Droit administration insuffisant.',
                            ]);
                        }

                        if (! $record->user_id || ! User::query()->whereKey((int) $record->user_id)->exists()) {
                            throw ValidationException::withMessages([
                                'password' => 'Compte Laravel introuvable.',
                            ]);
                        }
                    })
                    ->mutateDataUsing(function (array $data): array {
                        unset($data['password'], $data['password_confirmation']);

                        return $data;
                    })
                    ->after(function (EditAction $action, CrmUser $record): void {
                        $password = (string) ($action->getRawData()['password'] ?? '');

                        if ($password === '') {
                            return;
                        }

                        app(AdminAccountService::class)->assertStrongPassword($password);

                        $account = User::query()
                            ->lockForUpdate()
                            ->find((int) $record->user_id);

                        if (! $account) {
                            throw ValidationException::withMessages([
                                'password' => 'Compte Laravel introuvable.',
                            ]);
                        }

                        $account->forceFill([
                            'password' => $password,
                        ])->save();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCrmUsers::route('/'),
        ];
    }

    private static function canManageHubUsers(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $crmUser = $user->crmUser;
        if (! $crmUser instanceof CrmUser || ! $crmUser->active) {
            return false;
        }

        if ($crmUser->role === 'admin') {
            return true;
        }

        $crmUser->loadMissing('permissions:id,name,sort_order');

        return $crmUser->permissions
            ->contains(fn ($permission): bool => $permission->name === 'platform.manage_users');
    }
}
