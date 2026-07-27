<?php

namespace Modules\CrmEquipmentRentals\Filament\Resources\CrmEquipmentItems;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmEquipmentItem;
use App\Support\CrmTheme;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ColorEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\CrmEquipmentRentals\Filament\Resources\CrmEquipmentItems\Pages\ManageCrmEquipmentItems;
use UnitEnum;

class CrmEquipmentItemResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmEquipmentItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Location materiel';

    protected static ?string $navigationLabel = 'Materiel';

    protected static ?string $modelLabel = 'materiel';

    protected static ?string $pluralModelLabel = 'materiel';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(160),
                TextInput::make('inventory_code')
                    ->label('Code parc')
                    ->maxLength(80),
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('category_id')
                    ->label('Categorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                ColorPicker::make('color')
                    ->label('Couleur')
                    ->default(CrmTheme::primaryHex())
                    ->required(),
                TextInput::make('half_day_price')
                    ->label('Prix demi-journee')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('day_price')
                    ->label('Prix journee')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Toggle::make('show_half_day_price')
                    ->label('Afficher le prix demi-journee sur les cartes')
                    ->default(true),
                Toggle::make('show_day_price')
                    ->label('Afficher le prix journee sur les cartes')
                    ->default(true),
                Select::make('rental_mode')
                    ->label('Mode de location')
                    ->options([
                        'half_day_and_day' => 'Demi-journee et journee',
                        'day_only' => 'Journee uniquement',
                    ])
                    ->default('half_day_and_day')
                    ->required(),
                TextInput::make('deposit_amount')
                    ->label('Caution')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->default(100)
                    ->required(),
                Toggle::make('active')
                    ->label('Materiel actif')
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
                TextEntry::make('inventory_code')->label('Code parc')->placeholder('-'),
                TextEntry::make('site.name')->label('Site'),
                TextEntry::make('category.name')->label('Categorie')->placeholder('-'),
                ColorEntry::make('color')->label('Couleur'),
                IconEntry::make('active')->label('Actif')->boolean(),
                TextEntry::make('half_day_price')->label('Demi-journee')->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' EUR'),
                TextEntry::make('day_price')->label('Journee')->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' EUR'),
                IconEntry::make('show_half_day_price')->label('Prix demi-journee affiche')->boolean(),
                IconEntry::make('show_day_price')->label('Prix journee affiche')->boolean(),
                TextEntry::make('rental_mode')
                    ->label('Mode')
                    ->formatStateUsing(fn ($state): string => $state === 'day_only' ? 'Journee uniquement' : 'Demi-journee et journee'),
                TextEntry::make('deposit_amount')->label('Caution')->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' EUR'),
                TextEntry::make('rentals_count')->label('Locations')->counts('rentals'),
                TextEntry::make('description')->label('Description')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['category:id,name', 'site:id,name']))
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('inventory_code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('category.name')
                    ->label('Categorie')
                    ->sortable()
                    ->searchable()
                    ->placeholder('-'),
                ColorColumn::make('color')
                    ->label('Couleur'),
                TextColumn::make('day_price')
                    ->label('Journee')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2, ',', ' ').' EUR')
                    ->sortable(),
                IconColumn::make('show_half_day_price')
                    ->label('Demi-j. carte')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('show_day_price')
                    ->label('Journee carte')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('rental_mode')
                    ->label('Mode')
                    ->formatStateUsing(fn ($state): string => $state === 'day_only' ? 'Journee uniquement' : 'Demi + journee')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('rentals_count')
                    ->label('Locations')
                    ->counts('rentals')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('site')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Categorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
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
            'index' => ManageCrmEquipmentItems::route('/'),
        ];
    }
}
