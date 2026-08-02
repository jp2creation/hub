<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmModules;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmModule;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\CrmAdministration\Filament\Resources\CrmModules\Pages\ManageCrmModules;
use UnitEnum;

class CrmModuleResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmModule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Modules';

    protected static ?string $modelLabel = 'module';

    protected static ?string $pluralModelLabel = 'modules';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(120),
                TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(140)
                    ->helperText('Laisse vide pour le generer automatiquement.'),
                TextInput::make('route_path')
                    ->label('Route')
                    ->placeholder('/reservations')
                    ->maxLength(160),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->default(100)
                    ->required(),
                TextInput::make('menu_badge')
                    ->label('Badge menu')
                    ->maxLength(40),
                ColorPicker::make('menu_badge_color')
                    ->label('Couleur du badge menu'),
                Toggle::make('show_menu_badge')
                    ->label('Afficher le badge'),
                Toggle::make('active')
                    ->label('Module actif')
                    ->default(true),
                Textarea::make('description')
                    ->label('Description')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Nom'),
                TextEntry::make('slug')->label('Slug'),
                TextEntry::make('route_path')->label('Route'),
                TextEntry::make('sort_order')->label('Ordre'),
                TextEntry::make('menu_badge')->label('Badge'),
                TextEntry::make('menu_badge_color')->label('Couleur badge'),
                IconEntry::make('active')->label('Actif')->boolean(),
                TextEntry::make('description')->label('Description')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('route_path')
                    ->label('Route')
                    ->searchable(),
                TextColumn::make('menu_badge')
                    ->label('Badge')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('menu_badge_color')
                    ->label('Couleur badge')
                    ->placeholder('-')
                    ->toggleable(),
                IconColumn::make('active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Actif')
                    ->trueLabel('Actifs')
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
            'index' => ManageCrmModules::route('/'),
        ];
    }
}
