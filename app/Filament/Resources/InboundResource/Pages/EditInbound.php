<?php

namespace App\Filament\Resources\InboundResource\Pages;

use App\Filament\Resources\InboundResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditInbound extends EditRecord
{
    protected static string $resource = InboundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }


    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('اینباند ویرایش شد');

    }
}
