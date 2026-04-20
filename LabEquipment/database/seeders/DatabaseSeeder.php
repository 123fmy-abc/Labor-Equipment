<?php

namespace database\seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'account' => '25010420511',
                'name'    => '张梓潼',
                'email'   => '2704868796@qq.com',
                'code'=>'111111',
                'password'=> Hash::make('123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420522',
                'name'    => '柴国继',
                'email'   => '2835129893@qq.com',
                'password'=> Hash::make('123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420533',
                'name'    => '伏明月',
                'email'   => '3227605507@qq.com',
                'password'=> Hash::make('123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'account'           => $userData['account'],
                    'name'              => $userData['name'],
                    'password'          => $userData['password'],
                    'role'              => $userData['role'],
                    'email_verified_at' => $userData['email_verified_at'],
                ]
            );
        }
    }
}
