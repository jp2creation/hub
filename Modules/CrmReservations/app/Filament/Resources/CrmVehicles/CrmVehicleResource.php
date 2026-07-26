<?php

namespace Modules\CrmReservations\Filament\Resources\CrmVehicles;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmVehicle;
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
use Modules\CrmReservations\Filament\Resources\CrmVehicles\Pages\ManageCrmVehicles;
use UnitEnum;

class CrmVehicleResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmVehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Reservations';

    protected static ?string $navigationLabel = 'Vehicules';

    protected static ?string $modelLabel = 'vehicule';

    protected static ?string $pluralModelLabel = 'vehicules';

    protected static ?int $navigationSort = 20;

    private static function optionalTimeField(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->type('time')
            ->formatStateUsing(fn ($state): ?string => blank($state) ? null : substr((string) $state, 0, 5))
            ->dehydrateStateUsing(fn ($state): ?string => blank($state) ? null : substr((string) $state, 0, 5))
            ->helperText('Vide = planning vehicule par defaut : 06:00-19:30.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required()
                    ->maxLength(160),
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                ColorPicker::make('color')
                    ->label('Couleur')
                    ->default(CrmTheme::primaryHex())
                    ->required(),
                self::optionalTimeField('day_start_time', 'Ouverture journee'),
                self::optionalTimeField('day_end_time', 'Fermeture journee'),
                Toggle::make('active')
                    ->label('Vehicule actif')
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
                TextEntry::make('site.name')->label('Site'),
                ColorEntry::make('color')->label('Couleur'),
                IconEntry::make('active')->label('Actif')->boolean(),
                TextEntry::make('reservation_hours')
                    ->label('Horaires jour')
                    ->state(fn (?CrmVehicle $record): string => $record?->reservationHoursLabel($record->site) ?? '06:00-19:30'),
                TextEntry::make('reservations_count')->label('Reservations')->counts('reservations'),
                TextEntry::make('description')->label('Description')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('site:id,name,morning_start,afternoon_end'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->sortable()
                    ->searchable(),
                ColorColumn::make('color')
                    ->label('Couleur'),
                TextColumn::make('reservation_hours')
                    ->label('Horaires jour')
                    ->state(fn (CrmVehicle $record): string => $record->reservationHoursLabel($record->site))
                    ->sortable(false),
                IconColumn::make('active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('reservations_count')
                    ->label('Reservations')
                    ->counts('reservations')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('site')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('active')
                    ->label('Actif')
                    ->trueLabel('Actifs')
                    ->falseLabel('Masques'),
            ])
            ->defaultSort('name')
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
            'index' => ManageCrmVehicles::route('/'),
        ];
    }
}
