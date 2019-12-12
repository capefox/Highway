<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Driver;
use App\Repositories\PushNotification;

class MakeDriverOffline extends Command
{
    protected $signature = 'driver:offline';
    protected $description = 'This command runs every hour and makes offline those driver whose location not updated for past 2 hour.';

    public function __construct(PushNotification $firebase)
    {
        parent::__construct();
        $this->firebase = $firebase;
    }

    public function handle()
    {
        $this->info('MakeDriverOffline@handle --> started');


        $now = Carbon::now();
        $checkintime = $now->copy()->subHours(2);

        /** fetch driver push device tokens, those locations not updated */
        $drivers = Driver::select(["id"])
        ->where('is_approved', true)
        ->where('is_available', true)
        ->where("location_updated_at", "<", $checkintime)
        ->with('deviceTokens:device_token,entity_id')
        ->chunk(50, function($drivers){

            /** make offline drivers */
            $driverids = $drivers->pluck('id')->toArray();
            Driver::whereIn("id", $driverids)->update([ "is_available" => false ]);


            /** get only tokens */
            $deviceTokens = $drivers->pluck('deviceTokens.*.device_token')->flatten()->toArray();

            /** send push message */
            $this->firebase
                ->setTitle("Location update paused")
                ->setBody("Sorry!! Your location was not updated for more than 2 hours, so we are making you offline. You can make youself online back by pressing online button.")
                ->setPriority(PushNotification::HIGH)
                ->setDeviceTokens($deviceTokens, false)
                ->push();

        });

        
        $this->info('MakeDriverOffline@handle --> ended');  
    }
}
