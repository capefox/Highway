<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\DriverBookingBroadcast;

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
    //trip_started
    //trip_ended

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
        } else if($this->status == 'driver_started') {
            return "Driver on the way";
        } else if($this->status == 'trip_started') {
            return "Ongoing";
        } else if($this->status == 'trip_ended' && $this->payment_status == "PAID") {
            return "Completed";
        } else if($this->status == 'trip_ended' && $this->payment_status == "NO_PAID") {
            return "Payment pending";
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



    /** only date */
    public function onlyDate()
    {
        return Carbon::parse($this->datetime, 'UTC')->setTimezone('Asia/Kolkata')->format('d/m/Y');
    }


    /** only time */
    public function onlyTime()
    {
        return Carbon::parse($this->datetime, 'UTC')->setTimezone('Asia/Kolkata')->format('h:i A');
    }



    public function bookingDateTime()
    {
        return Carbon::parse($this->created_at, 'UTC')->setTimezone('Asia/Kolkata')->format('d.m.Y h:i A');
    }


    /**
     *  relatitionship with invoices
     */
    public function invoice()
    {
        return $this->belongsTo('App\Models\RideRequestInvoice', 'invoice_id');
    }




    /** get driver booking action with booking */
    public static function getDriverBookingAction($driverid)
    {

        /** check driver has any pending request */
        $booking = DriverBooking::join(DriverBookingBroadcast::table(), DriverBookingBroadcast::table().".booking_id", "=", DriverBooking::table().".id")
            ->where(DriverBookingBroadcast::table().".driver_id", $driverid)
            ->where(DriverBookingBroadcast::table().".status", "pending")
            ->select(DriverBooking::table().".*")
            ->first();

        if($booking) {
            return [$booking, "request_to_accept"];
        }


        /** check if driver has any onging booking */
        $booking = DriverBooking::with("package", "user", "invoice")->where("driver_id", $driverid)->whereIn("status", ["driver_started", "driver_reached", "trip_started"])->first();
        if($booking) {
            return [$booking, "ongoing_request"];
        }

        /** check if driver any booking has to give rating to user */
        $booking = DriverBooking::where("driver_id", $driverid)->whereIn("status", ["trip_ended"])->where("user_rating", 0)->first();
        if($booking) {
            return [$booking, "rating"];
        }

        return [null, null];


    }





}