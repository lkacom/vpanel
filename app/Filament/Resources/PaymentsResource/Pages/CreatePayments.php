<?php

namespace App\Filament\Resources\PaymentsResource\Pages;

use App\Filament\Resources\PaymentsResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePayments extends CreateRecord
{
    protected static string $resource = PaymentsResource::class;
}
