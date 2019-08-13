<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverBooking extends Model
{

    protected $table = 'driver_bookings';
    
    const NOT_PAID = 'NOT_PAID';
    const PAID = 'PAID';

    protected $appends = ["status_text", "car_transmission_type"];
    
    //status can be 
    //pending, 
    //waiting_for_drivers_to_accept, 
    //driver_assigned, 
    //user_canceled, 
    //driver_canceled, 
    //driver_started
    //driver_reached
    //started
    //ended

    public static function table()
    {
        return "driver_bookings";
    }

    public function getStatusTextAttribute()
    {
        if($this->status == 'pending') {
            return "Pending";
        } else if($this->status == 'waiting_for_drivers_to_accept') {
            return "Waiting for driver";
        } else if($this->status == 'driver_assigned') {
            return "Driver assigned";
        }
    }

    public function getCarTransmissionTypeAttribute()
    {
        return $this->car_transmission == "10" ? "Manual" : "Automatic";
    }

    
    /** relation with user */
    public function user()
    {
        return $this->belongsTo("App\Models\User", "user_id");
    }


    /** relation with driver */
    public function driver()
    {
        return $this->belongsTo("App\Models\Driver", "driver_id");
    }


    public function package()
    {
        return $this->belongsTo("App\Models\HirePackage", "package_id");
    }


}