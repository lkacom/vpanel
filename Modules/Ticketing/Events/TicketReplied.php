<?php

namespace Modules\Ticketing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Ticketing\Models\TicketReply;

class TicketReplied
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reply;

    public function __construct(TicketReply $reply)
    {
        $this->reply = $reply;
    }
}
