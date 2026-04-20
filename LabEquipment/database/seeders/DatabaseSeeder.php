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
                'college'=>'计算机与软件学院',
                'major'=>'软件工程',
                'password'=> Hash::make('zzt123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420522',
                'name'    => '柴国继',
                'email'   => '2835129893@qq.com',
                'college'=>'计算机与软件学院',
                'major'=>'软件工程',
                'password'=> Hash::make('cgj123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420533',
                'name'    => '伏明月',
                'email'   => '3227605507@qq.com',
                'college'=>'计算机与软件学院',
                'major'=>'计算机科学与技术',
                'password'=> Hash::make('fmy123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420544',
                'name'    => '唐新雨',
                'email'   => '972536599@qq.com',
                'college'=>'计算机与软件学院',
                'major'=>'软件工程',
                'password'=> Hash::make('txy123456'),
                'role'    => 'admin',
                'email_verified_at' => now(),
            ],
            [
                'account' => '25010420544',
                'name'    => '曹诚俊',
                'email'   => '',
                'college'=>'计算机与软件学院',
                'major'=>'软件工程',
                'password'=> Hash::make('ccj123456'),
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
                    'email'             => $userData['email'],
                    'college'           => $userData['college'],
                    'major'             => $userData['major'],
                    'password'          => $userData['password'],
                    'role'              => $userData['role'],
                    'email_verified_at' => $userData['email_verified_at'],
                ]
            );
        }
    }
}
