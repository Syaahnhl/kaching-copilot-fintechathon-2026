<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Beralih ke Synchronous Queue (Instan)
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $title;
    public $message;

    public function __construct($userId, $title = "Notifikasi Kasir", $message = "Ada pembaruan data")
    {
        $this->userId = $userId;
        $this->title = $title;
        $this->message = $message;
    }

    // Server akan memancarkan sinyal di channel khusus toko ini (Misal: store.1)
    public function broadcastOn()
    {
        return new Channel('store.' . $this->userId);
    }

    // Nama event yang dipancarkan secara eksplisit untuk Android
    public function broadcastAs()
    {
        return 'order.updated';
    }
}
