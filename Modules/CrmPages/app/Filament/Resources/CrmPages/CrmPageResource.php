<?php

namespace Modules\CrmPages\Filament\Resources\CrmPages;

use App\Filament\Concerns\AuthorizesResourceWithPolicy;
use App\Models\CrmPage;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
use Modules\CrmAdministration\Filament\Resources\CrmMenuItems\CrmMenuItemResource;
use Modules\CrmPages\Filament\Resources\CrmPages\Pages\ManageCrmPages;
use UnitEnum;

class CrmPageResource extends Resource
{
    use AuthorizesResourceWithPolicy;

    protected static ?string $model = CrmPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Administration HUB';

    protected static ?string $navigationLabel = 'Pages HUB';

    protected static ?string $modelLabel = 'page HUB';

    protected static ?string $pluralModelLabel = 'pages HUB';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Titre')
                    ->required()
                    ->maxLength(160),
                TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(180)
                    ->helperText('Laisse vide pour le generer automatiquement.'),
                TextInput::make('excerpt')
                    ->label('Resume')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('content')
                    ->label('Contenu')
                    ->required()
                    ->rows(12)
                    ->columnSpanFull()
                    ->helperText('Tu peux utiliser des paragraphes, listes simples et sauts de ligne.'),
                Select::make('icon_key')
                    ->label('Icone')
                    ->options(CrmMenuItemResource::iconOptions())
                    ->default('article')
                    ->searchable()
                    ->native(false),
                TextInput::make('sort_order')
                    ->label('Ordre')
                    ->numeric()
                    ->required()
                    ->default(100),
                Toggle::make('active')
                    ->label('Page publiee')
                    ->default(true),
                Toggle::make('show_in_menu')
                    ->label('Afficher dans le menu')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title')->label('Titre'),
                TextEntry::make('slug')->label('Slug'),
                TextEntry::make('route_path')->label('URL HUB'),
                TextEntry::make('excerpt')->label('Resume')->columnSpanFull(),
                TextEntry::make('content')->label('Contenu')->markdown()->columnSpanFull(),
                TextEntry::make('icon_key')->label('Icone'),
                TextEntry::make('sort_order')->label('Ordre'),
                IconEntry::make('active')->label('Publiee')->boolean(),
                IconEntry::make('show_in_menu')->label('Menu')->boolean(),
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
                TextColumn::make('route_path')
                    ->label('URL')
                    ->state(fn (CrmPage $record): string => $record->route_path)
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('icon_key')
                    ->label('Icone')
                    ->badge()
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Publiee')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('show_in_menu')
                    ->label('Menu')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Ordre')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Modifie')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Publiee')
                    ->trueLabel('Publiees')
                    ->falseLabel('Brouillons'),
                TernaryFilter::make('show_in_menu')
                    ->label('Dans le menu')
                    ->trueLabel('Affichees')
                    ->falseLabel('Masquees'),
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
            'index' => ManageCrmPages::route('/'),
        ];
    }
}
