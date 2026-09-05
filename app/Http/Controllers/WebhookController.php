<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {

        $data = $request->all();


        Log::info('NOWPayments Webhook Received:', $data);


        if (isset($data['payment_status']) && in_array($data['payment_status'], ['finished', 'confirmed', 'sending'])) {

            $orderId = $data['order_id'] ?? null;
            $order = Order::find($orderId);

            if ($order && $order->status !== 'paid') {

                $order->update([
                    'status' => 'paid',
                    'nowpayments_payment_id' => $data['payment_id'],
                ]);

                Log::info("Order #{$order->id} has been paid successfully.");
            }
        }


        return response()->json(['message' => 'Webhook Handled'], 200);
    }
}
