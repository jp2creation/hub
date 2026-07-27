<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmSites;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmSite;
use App\Support\CrmTheme;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\CrmAdministration\Filament\Resources\CrmSites\Pages\ManageCrmSites;
use UnitEnum;

class CrmSiteResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmSite::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'HUB';

    protected static ?string $navigationLabel = 'Sites';

    protected static ?string $modelLabel = 'site';

    protected static ?string $pluralModelLabel = 'sites';

    protected static ?int $navigationSort = 10;

    private static function timeField(string $name, string $label, string $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->type('time')
            ->default($default)
            ->required()
            ->formatStateUsing(fn ($state): string => substr((string) ($state ?: $default), 0, 5))
            ->dehydrateStateUsing(fn ($state): string => substr((string) ($state ?: $default), 0, 5));
    }

    private static function hoursLabel(?CrmSite $site): string
    {
        if (! $site) {
            return '07:30-12:00 / 13:30-17:30';
        }

        return sprintf(
            '%s-%s / %s-%s',
            substr((string) ($site->morning_start ?: '07:30'), 0, 5),
            substr((string) ($site->morning_end ?: '12:00'), 0, 5),
            substr((string) ($site->afternoon_start ?: '13:30'), 0, 5),
            substr((string) ($site->afternoon_end ?: '17:30'), 0, 5),
        );
    }

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
                    ->helperText('Laisse vide pour le generer depuis le nom.'),
                Toggle::make('active')
                    ->label('Site actif')
                    ->default(true),
                TextInput::make('address')
                    ->label('Adresse')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('phone')
                    ->label('Telephone')
                    ->tel()
                    ->maxLength(40),
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->maxLength(190),
                ColorPicker::make('color')
                    ->label('Couleur')
                    ->default(CrmTheme::primaryHex())
                    ->hexColor()
                    ->required(),
                self::timeField('morning_start', 'Matin debut', '07:30'),
                self::timeField('morning_end', 'Matin fin', '12:00'),
                self::timeField('afternoon_start', 'Apres-midi debut', '13:30'),
                self::timeField('afternoon_end', 'Apres-midi fin', '17:30'),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')->label('Nom'),
                TextEntry::make('slug')->label('Slug'),
                IconEntry::make('active')->label('Actif')->boolean(),
                ColorEntry::make('color')->label('Couleur'),
                TextEntry::make('address')->label('Adresse')->placeholder('Non renseignee'),
                TextEntry::make('phone')->label('Telephone')->placeholder('Non renseigne'),
                TextEntry::make('email')->label('E-mail')->placeholder('Non renseigne'),
                TextEntry::make('opening_hours')
                    ->label('Horaires')
                    ->state(fn (?CrmSite $record): string => self::hoursLabel($record)),
                TextEntry::make('vehicles_count')->label('Vehicules')->counts('vehicles'),
                TextEntry::make('reservations_count')->label('Reservations')->counts('reservations'),
                TextEntry::make('equipment_items_count')->label('Materiel')->counts('equipmentItems'),
                TextEntry::make('equipment_rentals_count')->label('Locations materiel')->counts('equipmentRentals'),
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
                ColorColumn::make('color')
                    ->label('Couleur'),
                TextColumn::make('phone')
                    ->label('Telephone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('address')
                    ->label('Adresse')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opening_hours')
                    ->label('Horaires')
                    ->state(fn (?CrmSite $record): string => self::hoursLabel($record)),
                IconColumn::make('active')
                    ->label('Actif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('vehicles_count')
                    ->label('Vehicules')
                    ->counts('vehicles')
                    ->sortable(),
                TextColumn::make('reservations_count')
                    ->label('Reservations')
                    ->counts('reservations')
                    ->sortable(),
                TextColumn::make('equipment_items_count')
                    ->label('Materiel')
                    ->counts('equipmentItems')
                    ->sortable(),
                TextColumn::make('equipment_rentals_count')
                    ->label('Locations materiel')
                    ->counts('equipmentRentals')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Modifie')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
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
            'index' => ManageCrmSites::route('/'),
        ];
    }
}
