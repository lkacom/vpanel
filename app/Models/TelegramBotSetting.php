<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBotSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
