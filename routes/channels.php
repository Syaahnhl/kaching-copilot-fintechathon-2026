<?php

use Illuminate\Support\Facades\Broadcast;

// Ini channel bawaan Laravel (Biarkan saja)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 👇 [BARU] INI PINTU UNTUK KDS DAPUR! WAJIB DIBUKA 👇
Broadcast::channel('store.{id}', function ($user, $id) {
    // Demi keamanan, cuma owner toko (user yang lagi login) yang boleh dengerin channel ini
    return (int) $user->id === (int) $id;
});
