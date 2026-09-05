<?php

namespace Modules\Ticketing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Ticketing\Models\Ticket;

class TicketController extends Controller
{
    public function create()
    {
        return view('ticketing::tickets.create');

    }



    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'required|in:low,medium,high',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);




        $ticketData = [
            'subject' => $request->subject,
            'message' => $request->message,
            'priority' => $request->priority,
            'status' => 'open',
        ];

        $ticket = Auth::user()->tickets()->create($ticketData);


        $replyData = [
            'user_id' => Auth::id(),
            'message' => $request->message,
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }

        $ticket->replies()->create($replyData);

        return redirect()->route('dashboard')->with('status', 'تیکت شما با موفقیت ارسال شد.');
    }

    public function show(Ticket $ticket)
    {
        if (Auth::id() !== $ticket->user_id) {
            abort(403);
        }
        return view('ticketing::tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        if (Auth::id() !== $ticket->user_id) {
            abort(403);
        }

        $request->validate([
            'message' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        $replyData = [
            'user_id' => Auth::id(),
            'message' => $request->message,
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }

        $ticket->replies()->create($replyData);
        $ticket->update(['status' => 'open']);

        return back()->with('status', 'پاسخ شما با موفقیت ثبت شد.');
    }



}
