<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmMenuGroups;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmMenuGroup;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
use Modules\CrmAdministration\Filament\Resources\CrmMenuGroups\Pages\ManageCrmMenuGroups;
use UnitEnum;

class CrmMenuGroupResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmMenuGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Sections de navigation';

    protected static ?string $modelLabel = 'section de navigation';

    protected static ?string $pluralModelLabel = 'sections de navigation';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('menu_key')
                    ->label('Cle')
                    ->required()
                    ->maxLength(80)
                    ->disabledOn('edit'),
                TextInput::make('title')
                    ->label('Titre affiche')
                    ->required()
                    ->maxLength(120),
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
                TextEntry::make('title')->label('Titre'),
                TextEntry::make('menu_key')->label('Cle'),
                TextEntry::make('sort_order')->label('Ordre'),
                IconEntry::make('active')->label('Visible')->boolean(),
                TextEntry::make('items_count')->label('Pages')->counts('items'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Titre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('menu_key')
                    ->label('Cle')
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Visible')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Pages')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
            ])
            ->filters([
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
            'index' => ManageCrmMenuGroups::route('/'),
        ];
    }
}
