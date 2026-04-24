<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder {
    public function run(): void {
        User::firstOrCreate(
            ['email' => 'test@lisa.fr'],
            [
                'name'     => 'Léa Mercier',
                'password' => Hash::make('Lisa2026!'),
                'service'  => 'Méd. interne — 4e Sud',
            ]
        );
    }
}
