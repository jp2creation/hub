<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmPermissions;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmPermission;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\CrmAdministration\Filament\Resources\CrmPermissions\Pages\ManageCrmPermissions;
use UnitEnum;

class CrmPermissionResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmPermission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Permissions HUB';

    protected static ?string $modelLabel = 'permission HUB';

    protected static ?string $pluralModelLabel = 'permissions HUB';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Cle')
                    ->required()
                    ->maxLength(160),
                TextInput::make('label')
                    ->label('Libelle')
                    ->required()
                    ->maxLength(190),
                TextInput::make('group_label')
                    ->label('Groupe')
                    ->required()
                    ->maxLength(80),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->required()
                    ->default(100),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('label')->label('Libelle'),
                TextEntry::make('name')->label('Cle'),
                TextEntry::make('group_label')->label('Groupe'),
                TextEntry::make('sort_order')->label('Ordre'),
                TextEntry::make('users_count')->label('Utilisateurs')->counts('users'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Libelle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Cle')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('group_label')
                    ->label('Groupe')
                    ->badge()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Utilisateurs')
                    ->counts('users')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('group_label')
                    ->label('Groupe')
                    ->options(fn (): array => CrmPermission::query()
                        ->orderBy('group_label')
                        ->pluck('group_label', 'group_label')
                        ->all()),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
            'index' => ManageCrmPermissions::route('/'),
        ];
    }
}
