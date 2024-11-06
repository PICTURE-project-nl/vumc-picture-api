<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Notifications\TestMail;
use App\Notifications\Mail;
use Notification;
use Illuminate\Support\Str;

class SendTestMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-test-mail';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $email = 'jorrit@activecollective.nl';

        Notification::route('mail', $email)->notify((new TestMail()));
    }
}
