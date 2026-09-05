<?php

namespace Modules\Referral\Filament\Resources\ReferralResource\Pages;

use Modules\Referral\Filament\Resources\ReferralResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditReferral extends EditRecord
{
    protected static string $resource = ReferralResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
