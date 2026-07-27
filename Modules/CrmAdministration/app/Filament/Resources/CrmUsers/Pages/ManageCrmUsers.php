<?php

namespace Modules\CrmAdministration\Filament\Resources\CrmUsers\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Modules\CrmAdministration\Filament\Resources\CrmUsers\CrmUserResource;

class ManageCrmUsers extends ManageRecords
{
    protected static string $resource = CrmUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth(Width::SevenExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter(),
        ];
    }
}
