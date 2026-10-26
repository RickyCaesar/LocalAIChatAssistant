<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-admin', function () {
    $name = trim((string) $this->ask('Nama admin'));
    $email = trim((string) $this->ask('Email admin'));
    $password = (string) $this->secret('Password admin (min. 6 karakter)');

    $validator = Validator::make(
        ['name' => $name, 'email' => $email, 'password' => $password],
        [
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:6',
        ]
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'is_admin' => true,
    ]);

    $this->info("Admin {$email} dibuat.");
    return 0;
})->purpose('Membuat akun admin');
