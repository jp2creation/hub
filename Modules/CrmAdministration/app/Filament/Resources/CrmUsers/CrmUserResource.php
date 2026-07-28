<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmUsers;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmUser;
use App\Models\User;
use BackedEnum;
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
use Illuminate\Validation\Rules\Password;
use Modules\CrmAdministration\Filament\Resources\CrmUsers\Pages\ManageCrmUsers;
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
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(190),
                        TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->maxLength(40),
                        Select::make('role')
                            ->label('Profil')
                            ->options(CrmUser::roleOptions())
                            ->required()
                            ->default('user'),
                        Select::make('roles')
                            ->label('Rôles Filament')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Accès technique à l’administration Filament.'),
                        Toggle::make('active')
                            ->label('Compte actif')
                            ->default(true),
                        TextInput::make('password')
                            ->label('Nouveau mot de passe')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->visible(fn (): bool => self::canManageHubUsers())
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
                            ->visible(fn (): bool => self::canManageHubUsers())
                            ->requiredWith('password')
                            ->same('password')
                            ->dehydrated(false)
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
                TextEntry::make('role')->label('Profil')->badge(),
                TextEntry::make('roles.name')->label('Rôles Filament')->badge(),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query)
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
                TextColumn::make('email')
                    ->label('E-mail')
                    ->placeholder('Non renseigné')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Rôles Filament')
                    ->badge()
                    ->toggleable(),
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
                ViewAction::make(),
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->mutateDataUsing(function (array $data): array {
                        unset($data['password_confirmation']);

                        return $data;
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
