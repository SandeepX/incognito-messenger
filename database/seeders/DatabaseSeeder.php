<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Alice', 'Bob', 'Charlie', 'Dana'] as $name) {
            User::updateOrCreate(
                ['email' => strtolower($name).'@example.com'],
                ['name' => $name, 'password' => bcrypt('password')],
            );
        }
    }
}
