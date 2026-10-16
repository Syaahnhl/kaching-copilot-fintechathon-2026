<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Exception;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Cek apakah user sudah ada berdasarkan email atau google_id
            $findUser = User::where('google_id', $googleUser->id)->orWhere('email', $googleUser->email)->first();

            if ($findUser) {
                // Jika user sudah ada, update google_id (kalau misal sebelumnya daftar manual)
                $findUser->update(['google_id' => $googleUser->id]);
                Auth::login($findUser);

                return redirect()->intended('dashboard'); // Sesuaikan dengan route dashboard Mas
            } else {
                // Jika user BARU pertama kali daftar pakai Google
                $newUser = User::create([
                    'name'      => $googleUser->name,
                    'email'     => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'password'  => encrypt('password_dummy_12345'), // Kasih password acak

                    // Catatan: Jika DB Kaching mewajibkan isi 'store_name' dan 'phone', 
                    // Mas harus isi dengan string kosong/dummy dulu, atau set jadi nullable di database.
                    // 'store_name' => 'Toko ' . $googleUser->name,
                    // 'phone' => '0000000000'
                ]);

                Auth::login($newUser);
                return redirect()->intended('dashboard');
            }
        } catch (Exception $e) {
            return redirect('/login')->withErrors(['Gagal login dengan Google. Silakan coba lagi.']);
        }
    }
}
