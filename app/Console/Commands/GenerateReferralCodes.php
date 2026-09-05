<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateReferralCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referral:generate-codes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate referral codes for existing users who do not have one.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Searching for users without a referral code...');


        $usersWithoutCode = User::whereNull('referral_code')->get();

        if ($usersWithoutCode->isEmpty()) {
            $this->info('All users already have a referral code. Nothing to do.');
            return 0;
        }

        $this->info("Found {$usersWithoutCode->count()} users. Generating codes...");
        $bar = $this->output->createProgressBar($usersWithoutCode->count());
        $bar->start();


        foreach ($usersWithoutCode as $user) {
            do {
                $code = 'REF-' . strtoupper(Str::random(6));
            } while (User::where('referral_code', $code)->exists());

            $user->referral_code = $code;
            $user->save();
            $bar->advance();
        }

        $bar->finish();
        $this->info("\nSuccessfully generated referral codes for all users.");

        return 0;
    }
}

