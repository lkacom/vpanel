<?php

namespace Modules\Ticketing\Filament\Resources\TicketResource\Pages;

use Modules\Ticketing\Filament\Resources\TicketResource;
use Modules\Ticketing\Events\TicketReplied;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components;

class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\Section::make('اطلاعات تیکت')
                    ->schema([
                        Components\TextEntry::make('subject')->label('موضوع'),
                        Components\TextEntry::make('user.name')->label('ارسال کننده'),
                        Components\TextEntry::make('status')
                            ->label('وضعیت')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'open' => 'warning',
                                'answered' => 'success',
                                'closed' => 'gray',
                                default => 'gray',
                            })->formatStateUsing(fn (string $state): string => match ($state) {
                                'open' => 'باز',
                                'answered' => 'پاسخ داده شده',
                                'closed' => 'بسته شده',
                                default => $state,
                            }),
                        Components\TextEntry::make('updated_at')->label('آخرین بروزرسانی')->since(),
                    ])->columns(2),

                Components\Section::make('تاریخچه مکالمه')
                    ->schema([
                        Components\ViewEntry::make('conversation')
                            ->label('')
                            ->view('filament.infolists.ticket-conversation'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->label('ارسال پاسخ')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->form([
                    Textarea::make('reply_message')->label('متن پاسخ شما')->required()->columnSpanFull(),
                    FileUpload::make('attachment')->label('فایل ضمیمه (اختیاری)')->disk('public')->directory('ticket_attachments')->visibility('public')->openable(),
                ])
                ->action(function (array $data) {
                    $attachmentPath = null;
                    if (!empty($data['attachment'])) {
                        $attachmentPath = is_array($data['attachment']) ? $data['attachment'][0] : $data['attachment'];
                    }

                    $reply = $this->getRecord()->replies()->create([
                        'user_id' => Auth::id(),
                        'message' => $data['reply_message'],
                        'attachment_path' => $attachmentPath,
                    ]);

                    $this->getRecord()->update(['status' => 'answered']);

                    TicketReplied::dispatch($reply);

                    Notification::make()->title('پاسخ با موفقیت ارسال شد.')->success()->send();
                }),

            Actions\Action::make('closeTicket')
                ->label('بستن تیکت')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->status !== 'closed')
                ->action(function () {
                    $this->getRecord()->update(['status' => 'closed']);
                    Notification::make()->title('تیکت با موفقیت بسته شد.')->success()->send();
                }),

            Actions\EditAction::make(),
        ];
    }
}
