<?php

namespace App\Repositories;

use Mail;
use App\Models\Setting;
use App\Mail\WelcomeUser;
use App\Mail\WelcomeDriver;

class Email 
{

	public function __construct(Setting $setting)
	{
		$this->setting = $setting;
    }
    

    /**
     * reutrns mail send activated or not
     */
    public function isEmailSendActive()
    {
        return ($this->setting->get('is_mail_send_activated') == 'true');
    }



    /**
     * send user ride request invoice
     */
    /**
     * send new user registration email
     */
    public function sendUserRideRequestInvoiceEmail()
    {
        //if email send is not active from admin panel return from here
        if(!$this->isEmailSendActive()) {
            \Log::info('EMAIL_SEND_NOT_ACTIVATED');
            return false;
        }

        try {


            $rideRequest = \App\Models\RideRequest::find(1);
            $user = \App\Models\User::find($rideRequest->user_id);
            $driver = \App\Models\Driver::find($rideRequest->driver_id);
            $invoice = \App\Models\RideRequestInvoice::find($rideRequest->ride_invoice_id);
        
            $resCode = Mail::to($user->email)->send(new \App\Mail\RideRequestInvoice($rideRequest, $user, $driver, $invoice));
            \Log::info('MAIL PUSHED TO QUEUE, RESCODE :' . $resCode);
        
        } catch(\Exception $e) {
            \Log::info('MAIL PUSHED TO QUEUE ERROR :');
            \Log::info($e->getMessage());
            \Log::info($e->getFile());
            \Log::info($e->getLine());
            return false;
        }
        
        return true;
    }



    /**
     * send new user registration email
     */
    public function sendNewUserWelcomeEmail($user)
    {
        //if email send is not active from admin panel return from here
        if(!$this->isEmailSendActive()) {
            \Log::info('EMAIL_SEND_NOT_ACTIVATED');
            return false;
        }

        try {
            $resCode = Mail::to($user->email)->queue(new WelcomeUser($user));
            \Log::info('MAIL PUSHED TO QUEUE, RESCODE :' . $resCode);
        
        } catch(\Exception $e) {
            \Log::info('MAIL PUSHED TO QUEUE ERROR :');
            \Log::info($e->getMessage());
            \Log::info($e->getFile());
            \Log::info($e->getLine());
            return false;
        }

        return true;
        
    }



    /**
     * send new driver registration email
     */
    public function sendNewDriverWelcomeEmail($driver)
    {
        //if email send is not active from admin panel return from here
        if(!$this->isEmailSendActive()) {
            \Log::info('EMAIL_SEND_NOT_ACTIVATED');
            return false;
        }

        try {
            $resCode = Mail::to($driver->email)->queue(new WelcomeDriver($driver));
            \Log::info('MAIL PUSHED TO QUEUE, RESCODE :' . $resCode);
        
        } catch(\Exception $e) {
            \Log::info('MAIL PUSHED TO QUEUE ERROR :');
            \Log::info($e->getMessage());
            \Log::info($e->getFile());
            \Log::info($e->getLine());
            return false;
        }

        return true;
        
    }






}