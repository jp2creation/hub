<?php

namespace Modules\CrmReservations\Filament\Resources\CrmReservations;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmReservation;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\CrmReservations\Filament\Resources\CrmReservations\Pages\ManageCrmReservations;
use UnitEnum;

class CrmReservationResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Reservations';

    protected static ?string $navigationLabel = 'Reservations';

    protected static ?string $modelLabel = 'reservation';

    protected static ?string $pluralModelLabel = 'reservations';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('vehicle_id')
                    ->label('Vehicule')
                    ->relationship('vehicle', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('user_id')
                    ->label('Utilisateur HUB')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('title')
                    ->label('Titre')
                    ->maxLength(190),
                TextInput::make('contact_phone')
                    ->label('Telephone')
                    ->maxLength(40),
                DateTimePicker::make('start_at')
                    ->label('Debut')
                    ->displayFormat('d/m/Y H:i')
                    ->withoutSeconds()
                    ->required(),
                DateTimePicker::make('end_at')
                    ->label('Fin')
                    ->displayFormat('d/m/Y H:i')
                    ->withoutSeconds()
                    ->required(),
                Textarea::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title')->label('Titre')->placeholder('Reservation'),
                TextEntry::make('vehicle.name')->label('Vehicule'),
                TextEntry::make('site.name')->label('Site'),
                TextEntry::make('user_name')->label('Utilisateur'),
                TextEntry::make('contact_phone')->label('Telephone')->placeholder('-'),
                TextEntry::make('start_at')->label('Debut')->dateTime('d/m/Y H:i'),
                TextEntry::make('end_at')->label('Fin')->dateTime('d/m/Y H:i'),
                TextEntry::make('notes')->label('Notes')->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'site:id,name',
                'user:id,name',
                'vehicle:id,name',
            ]))
            ->columns([
                TextColumn::make('start_at')
                    ->label('Debut')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('end_at')
                    ->label('Fin')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('vehicle.name')
                    ->label('Vehicule')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user_name')
                    ->label('Utilisateur')
                    ->searchable(),
                TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->placeholder('Reservation'),
            ])
            ->filters([
                SelectFilter::make('site')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('vehicle')
                    ->label('Vehicule')
                    ->relationship('vehicle', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('a_venir')
                    ->label('Periode')
                    ->options([
                        'upcoming' => 'A venir',
                        'past' => 'Passees',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'upcoming' => $query->where('end_at', '>=', now()),
                            'past' => $query->where('end_at', '<', now()),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('start_at', 'desc')
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
            'index' => ManageCrmReservations::route('/'),
        ];
    }
}
