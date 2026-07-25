<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmMenuItems;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmMenuItem;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\CrmAdministration\Filament\Resources\CrmMenuItems\Pages\ManageCrmMenuItems;
use UnitEnum;

class CrmMenuItemResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmMenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Liens de menu';

    protected static ?string $modelLabel = 'lien de menu';

    protected static ?string $pluralModelLabel = 'liens de menu';

    protected static ?int $navigationSort = 40;

    public static function iconOptions(): array
    {
        return [
            'truck' => 'Camion',
            'package' => 'Colis',
            'creditCard' => 'Carte bancaire',
            'settings' => 'Parametres',
            'calendar' => 'Calendrier',
            'article' => 'Document',
            'fileText' => 'Page texte',
            'bookOpen' => 'Livre',
            'bookmark' => 'Favori',
            'infoCircle' => 'Information',
            'checklist' => 'Checklist',
            'table' => 'Tableau',
            'users' => 'Utilisateurs',
            'shield' => 'Bouclier',
            'mail' => 'Email',
            'message' => 'Message',
            'note' => 'Note',
            'briefcase' => 'HUB',
            'dashboard' => 'Dashboard',
            'category' => 'Categorie',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('item_key')
                    ->label('Cle')
                    ->required()
                    ->maxLength(120)
                    ->disabledOn('edit'),
                Select::make('group_key')
                    ->label('Groupe')
                    ->relationship('group', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('label')
                    ->label('Titre affiche')
                    ->required()
                    ->maxLength(160),
                Select::make('icon_key')
                    ->label('Icone')
                    ->options(static::iconOptions())
                    ->searchable()
                    ->native(false),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->required()
                    ->default(100),
                Toggle::make('active')
                    ->label('Visible')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('label')->label('Titre'),
                TextEntry::make('item_key')->label('Cle'),
                TextEntry::make('group.title')->label('Groupe'),
                TextEntry::make('icon_key')->label('Icone'),
                TextEntry::make('sort_order')->label('Ordre'),
                IconEntry::make('active')->label('Visible')->boolean(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('group:menu_key,title'))
            ->columns([
                TextColumn::make('label')
                    ->label('Titre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('group.title')
                    ->label('Groupe')
                    ->sortable(),
                TextColumn::make('icon_key')
                    ->label('Icone')
                    ->badge()
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Visible')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
                TextColumn::make('item_key')
                    ->label('Cle')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label('Groupe')
                    ->relationship('group', 'title')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('active')
                    ->label('Visible')
                    ->trueLabel('Visibles')
                    ->falseLabel('Masques'),
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
            'index' => ManageCrmMenuItems::route('/'),
        ];
    }
}
