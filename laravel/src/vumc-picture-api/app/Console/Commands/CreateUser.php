<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\User;
use Illuminate\Support\Str;


use Helper;


class CreateUser extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create {--confirm}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create super user by emailaddress';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
    */

     public function handle()
     {

        $confirm = $this->option('confirm');

        $createAccount = false;

        if ($confirm) {
            if ($this->confirm('Do you want to create an account? [enter to continue]', true)) {
                $createAccount = true;
            }

        } else {
            $createAccount = true;
        }

        if ($createAccount === true) {
            $fullName = $this->ask('What is your full name?');
            $emailAddress = $this->ask('What is your email address?');
            $password = $this->secret('What password do you want to use?');

            $user = new User;
            $user->institute = 'Open source community';
            $user->name = $fullName;
            $user->email = $emailAddress;
            $user->email_verified_at = now();
            $user->password = bcrypt($password);
            $user->super_user = true;
            $user->active = true;
            $user->activation_token = Str::random(32);
            $user->save();

            $this->info("Your account is created, " . $fullName);
        }
     }
}
