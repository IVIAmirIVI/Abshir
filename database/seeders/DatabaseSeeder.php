<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AuthorizationSeeder::class);
        if (! app()->environment('local')) {
            return;
        }
        $email = env('LOCAL_ADMIN_EMAIL');
        $password = env('LOCAL_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => env('LOCAL_ADMIN_NAME', 'Local Administrator'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );
        $user->roles()->syncWithoutDetaching([Role::where('code', Role::SUPER_ADMIN)->sole()->id]);
    }
}
