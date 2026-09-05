<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;

class RewardReferrerListener
{
    /**
     * Handle the event.
     */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;
        $user = $order->user;


        if ($user->referrer_id && $this->isFirstPaidOrder($user)) {
            $referrer = User::find($user->referrer_id);
            if ($referrer) {

                $settings = Setting::all()->pluck('value', 'key');
                $rewardAmount = (int) $settings->get('referral_referrer_reward', 0);

                if ($rewardAmount > 0) {

                    $referrer->increment('balance', $rewardAmount);


                    Transaction::create([
                        'user_id' => $referrer->id,
                        'amount' => $rewardAmount,
                        'type' => Transaction::TYPE_DEPOSIT, // نوع تراکنش واریز است
                        'status' => Transaction::STATUS_COMPLETED,
                        'description' => "پاداش دعوت از کاربر: " . $user->name,
                    ]);
                }
            }
        }
    }

    /**
     * Checks if this is the user's first ever paid order.
     */
    private function isFirstPaidOrder(User $user): bool
    {

        $paidOrdersCount = Order::where('user_id', $user->id)
            ->where('status', 'paid')
            ->whereNotNull('plan_id')
            ->count();

        return $paidOrdersCount === 1;
    }
}



