<?php

namespace App\Filament\Resources\InboundResource\Pages;

use App\Filament\Resources\InboundResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateInbound extends CreateRecord
{
    protected static string $resource = InboundResource::class;
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }




    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('اینباند ساخته شد');

    }
}
