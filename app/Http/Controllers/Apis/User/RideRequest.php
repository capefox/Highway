<?php

namespace App\Http\Controllers\Apis\User;

use App\Repositories\Api;
use App\Http\Controllers\Controller;
use DB;
use App\Models\Driver;
use App\Repositories\Email;
use App\Repositories\Gateway;
use Hash;
use App\Jobs\ProcessDriverRating;
use Illuminate\Http\Request;
use App\Models\RideRequest as Ride;
use App\Models\RideCancellationCharge as CancellationCharge;
use Illuminate\Validation\Rule;
use App\Models\Setting;
use App\Repositories\SocketIOClient;
use App\Models\Transaction;
use App\Repositories\Utill;
use Validator;
use App\Models\VehicleType;
use App\Models\Coupons\Coupon;
use App\Models\FakeLocation;
use App\Models\DriverBooking;

class RideRequest extends Controller
{

    /**
     * init dependencies
     */
    public function __construct(CancellationCharge $cCharge, Email $email, Transaction $transaction, SocketIOClient $socketIOClient, Utill $utill, Setting $setting, Api $api, Ride $rideRequest, VehicleType $vehicleType, Driver $driver)
    {
        $this->cCharge = $cCharge;
        $this->email = $email;
        $this->transaction = $transaction;
        $this->socketIOClient = $socketIOClient;
        $this->utill = $utill;
        $this->setting = $setting;
        $this->api = $api;
        $this->rideRequest = $rideRequest;
        $this->vehicleType = $vehicleType;
        $this->driver = $driver;
        $this->coupon = app('App\Models\Coupons\Coupon');
    }




    /**
     * returns payment modes for ride requests
     */
    public function getPaymentModes(Request $request)
    {
        return $this->api->json(true, 'PAYMENT_MODES', 'Payment modes', [
            'payment_modes' => $this->rideRequest->getPaymentModes()
        ]);
    }




    /**
     * check previous on going ride request
     * if payment not done, not completed, rating not given etc etc
     */
    public function checkRideRequest(Request $request)
    {

        list($booking, $booking_action) = DriverBooking::getDriverBookingActionForUser($request->auth_user->id);
        list($rideRequest, $driver, $invoice) = $this->getUserOngoingRequest($request->auth_user->id);
        return $this->api->json(true, 'RIDE_REQUEST_INFORMATION', 'Request informations', [
            "is_ride_request" => !!$rideRequest,
            'ride_request' => $rideRequest,
            'driver' => $driver,
            'invoice' => $invoice,
            "is_driver_booking" => !!$booking_action,
            "driver_booking_action" => $booking_action,
            "driver_booking" => $booking,
        ]);

    }

    protected function getUserOngoingRequest($userid)
    {
        /** check any ongoing request is there not  */
        $rideRequest = $this->rideRequest
            ->where('user_id', $userid)
            ->whereNotIn('ride_status', $this->rideRequest->notOngoigRideRequestStatusList())
            ->first();

        /** if no ride request, check if any ride completd by not driver rated */
        if(!$rideRequest) {
            $rideRequest = $this->rideRequest->where('user_id', $userid)->where('ride_status', Ride::COMPLETED)->where('driver_rating', 0)->first();
        }

        if(!$rideRequest) {
            return [null, null, null];
        }
        

        $driver = [
            'id' => $rideRequest->driver->id,
            'fname' => $rideRequest->driver->fname,
            'lname' => $rideRequest->driver->lname,
            'country_code' => $rideRequest->driver->country_code,
            'mobile_number' => $rideRequest->driver->mobile_number,
            'latidue' => $rideRequest->driver->latitude,
            'longitude' => $rideRequest->driver->longitude,
            'profile_photo_url' => $rideRequest->driver->profilePhotoUrl(),
            'vehicle_number' => $rideRequest->driver->vehicle_number,
            'vehicle_type' => $rideRequest->driver->vehicle_type,
            'rating' => $rideRequest->driver->rating,
        ];

        //take invoice if invoice is ready
        $invoice = null; //init invoice array empty
        if($rideRequest->ride_invoice_id != 0) {
            $invoice = $rideRequest->invoice->toArray();
            unset($rideRequest->invoice);
        }

        //removing driver object relationship from ride request
        unset($rideRequest->driver);

        return [$rideRequest, $driver, $invoice];

    }







    /**
     * cancel ride request
     */
    public function cancelRideRequest(Request $request)
    {
        
        /**
         *  if request_id in invalid or request not belongs to user
         *  or request status is not allowed to canceled
         */
        $rideRequest = $this->rideRequest->where('id', $request->ride_request_id)
        ->where('user_id', $request->auth_user->id)
        ->whereIn('ride_status', $this->rideRequest->rideRequestCancelAllowedStatusList())
        ->first();

        if(!$rideRequest) {
            return $this->api->json(false, 'INVALID_RIDE_REQUEST', 'Invalid ride request'); 
        }

        //getting driver
        $driver = $this->driver->find($rideRequest->driver_id);


        try {
            DB::beginTransaction();


            /** cancellation charge will be added here */
            if($rideRequest->ride_status == Ride::DRIVER_ACCEPTED || $rideRequest->ride_status == Ride::DRIVER_STARTED || $rideRequest->ride_status == Ride::DRIVER_REACHED) {

                $rideStartedAgo = intval($this->utill->getDiffMinute($rideRequest->created_at, date('Y-m-d H:i:s')));
                
                $allowedTime = $this->setting->get('ride_request_cancellation_charge_after_minute_trip_started')?:0; 
                $cancellationChargeAmt = $this->setting->get('ride_request_cancellation_charge')?:0.00;
                if($rideStartedAgo >= $allowedTime) {
                    $cCharge = new $this->cCharge;
                    $cCharge->user_id = $request->auth_user->id;
                    $cCharge->ride_request_id = $request->ride_request_id;
                    $cCharge->cancellation_charge = $cancellationChargeAmt;
                    $cCharge->status = CancellationCharge::NOT_APPLIED;
                    $cCharge->save();

                    $user = $request->auth_user;
                    $currencySymbol = $this->setting->get('currency_symbol');
                    $message = "You will be charged {$currencySymbol}{$cCharge->cancellation_charge} as cancellation ride on next ride.";
                    $user->sendPushNotification("Cancellation Charge Added", $message);
                    $user->sendSms($message);
                }

            }


            //chaning driver availability to 1
            $driver->is_available = 1;
            $driver->save();

            $rideRequest->ride_status = Ride::USER_CANCELED;
            $rideRequest->save();

            DB::commit();
        } catch(\Exception $e){
            DB::rollback();
            return $this->api->json(false,'SEVER_ERROR', 'Internal server error try again.');
        }

        

        // same notification data to be sent to user
        $notificationData = [
            'ride_request_id' => $rideRequest->id,
            'ride_status' => $rideRequest->ride_status,
        ];


        /**
         * send push notification to driver
         */
        $driver->sendPushNotification("Ride canceled", "User {$request->auth_user->fname} has canceled your ride request");


        /**
         * send socket push to driver
         */
        $this->socketIOClient->sendEvent([
            'to_ids' => $rideRequest->driver_id,
            'entity_type' => 'driver', //socket will make it uppercase
            'event_type' => 'ride_request_status_changed',
            'data' => $notificationData,
            "store_messsage" => true
        ]);



        return $this->api->json(true, 'RIDE_REQUEST_CANCELED', 'Ride Request canceled successfully'); 
           
    }




    /**
     * get nearby drivers 
     * used for ride reqeust basically ?vehicle_type is optional
     */
    public function getNearbyDrivers(Request $request)
    {
        //put log
        Api::log('NEARBY_DRIVERS_API_LAT_LNG', $request->lat_long);
        Api::log('NEARBY_DRIVERS_API_VEHICLE_TYPE', $request->vehicle_type);

        // taking latitude and longitude from request
        try {
            list($latitude, $longitude) = explode(',', $request->lat_long);
        } catch(\Exception $e) {
            return $this->api->json(false, 'LAT_LONG_FORMAT_INVALID', 'Latitude,longitude format invalid');
        }


        // validatinrideRequestCancelAllowedStatusListg vehicle type if exists
        if($request->vehicle_type != '' && !in_array($request->vehicle_type, $this->vehicleType->allCodes())) {
            return $this->api->json(false, 'VEHIVLE_TYPE_INVALID', 'Vehicle type is invalid.');
        }
        

        $radious = $this->setting->get('ride_request_driver_search_radius')?:7;
        $drivers = $this->driver->getNearbyDriversBuilder($latitude, $longitude, $radious);
        
        //if vehicle type is passed then filter drivers
        if($request->vehicle_type) {
            $drivers = $drivers->where($this->driver->getTableName().'.vehicle_type', $request->vehicle_type);
        }

        //filter drivers approved, available, connected to socket
        $dt = $this->driver->getTableName();
        $nearbyDriversDetails = $drivers->where($dt.'.is_approved', 1)
        ->where($dt.'.is_available', 1)
        //->where($dt.'.is_connected_to_socket', 1) // this is not required now
        ->orderBy($dt.'.rating', 'desc')
        ->select([
            $dt.'.id',            
            $dt.'.latitude',            
            $dt.'.longitude',
            $dt.'.vehicle_type',            
        ])
        ->take(50)->get();

        Api::log('NEARBY_DRIVERS_API_DRIVERS', $nearbyDriversDetails->toArray());


        /** import fake drivers and merge */
        $fakeDrivers = FakeLocation::fakeDriversWithService($latitude, $longitude, $radious, 'km', $request->vehicle_type);
        
        $nDrivers = array_merge($nearbyDriversDetails->toArray(), $fakeDrivers);

        return $this->api->json(true, 'NEARBY_DRIVERS', 'Nearby drivers', [
            'drivers' => $nDrivers
        ]);

    }






    /**
     * user can update payment mode after ride started
     * ride request status must in not INITIATED, USER_CANCELED, DRIVER_CANCELED, TRIP_ENDED, COMPLETED
     */
    public function updatePaymentMode(Request $request)
    {
        /** validate update city ride payment mode */
        $validator = Validator::make($request->all(), [
            'ride_request_id' => [
                'required',
                Rule::exists(Ride::tablename(), 'id')->where(function ($query) use($request) {
                    $query->where('user_id', $request->auth_user->id)
                        ->whereNotIn('ride_status', [Ride::INITIATED, Ride::USER_CANCELED, Ride::DRIVER_CANCELED, Ride::COMPLETED])
                        ->where('payment_mode', Ride::ONLINE);
                })
            ],
            'payment_mode' => 'required|in:'.implode(',', $this->rideRequest->getPaymentModes()),
        ]);


        if($validator->fails()) {
            return $this->api->json(false, 'ERROR', $validator->errors()->first());
        }

        /** fetch ride reqeust by id */
        $rideRequest = Ride::find($request->ride_request_id);

        try {
            DB::beginTransaction();

            /** change payment */
            $rideRequest->payment_mode = $request->payment_mode;


            /** if ride ended then, update invoice and transaction also */
            if($rideRequest->ride_status == Ride::TRIP_ENDED) {
                
                /** make ride reqeust payment status as paid and status as completed */
                $rideRequest->payment_status = Ride::PAID;
                $rideRequest->ride_status = Ride::COMPLETED;

                /** made invoice as paid */
                $invoice = $rideRequest->invoice;
                $invoice->payment_status = Ride::PAID;

                /** create transaction because payment successfull here */
                $transaction = new $this->transaction;
                $transaction->trans_id = $invoice->invoice_reference;
                $transaction->amount = $invoice->total;
                $transaction->currency_type = $this->setting->get('currency_code');
                $transaction->gateway = Ride::CASH;
                $transaction->payment_method = Ride::COD;
                $transaction->status = Transaction::SUCCESS;
                $transaction->save();

                /** add transaciton_table_id in invoice */
                $invoice->transaction_table_id = $transaction->id;
                $invoice->save();
            }

            /** ride request save call required uppper is portion called or not */
            $rideRequest->save();
            
            DB::commit();
        } catch(\Exception $e) {
            DB::rollback();
            $this->api->log('RAZORPAY_CHARGE_ERROR', $e);
            return $this->api->json(false, 'UNKOWN_ERROR', 'Unknown error. Try again or contact to service provider');
        }


        /** send notification to driver */
        $notificationData = ['ride_request_id' => $rideRequest->id, 'payment_mode' => $rideRequest->payment_mode];
        
        /**  send push notification to driver */
        $rideRequest->driver->sendPushNotification("Payment Mode Changed", "User has change the payment mode to : {$rideRequest->payment_mode}");

        /** send socket push to driver */
        $this->socketIOClient->sendEvent([
            'to_ids' => $rideRequest->driver_id,
            'entity_type' => 'driver', //socket will make it uppercase
            'event_type' => 'ride_request_paymentmode_changed',
            'data' => $notificationData
        ]);

        
        return $this->api->json(true, 'PAYMENT_MODE_CHANGED', 'Payment mode changed');
        
    }




    /**
     * initiate ride requet 
     * check if ongoing request is there dont allow to request
     */
    public function initiateRideRequest(Request $request)
    {

        /**
         * clear all previous initiated ride reuqests
         */
        $this->rideRequest
            ->where('user_id', $request->auth_user->id)
            ->where('ride_status', Ride::INITIATED)->forceDelete();


        /**
         * check any ongoing request alreay exists or not 
         * if so dont allow to create request
         */
        $rideRequest = $this->rideRequest
        ->where('user_id', $request->auth_user->id)
        ->whereNotIn('ride_status', $this->rideRequest->notOngoigRideRequestStatusList())
        ->first();

        if($rideRequest) {
            return $this->api->json(false, 'ONGOING_REQUEST', 'Ongoing request already present');
        }


        /**
         * validate ride request create 
         */
        list($latRegex, $longRegex) = $this->utill->regexLatLongValidate();
        $validator = Validator::make($request->all(), [
            'ride_vehicle_type' => 'required|in:'.implode(',', $this->vehicleType->allCodes()),
            'source_address' => 'required|min:1|max:256', 
            'source_latitude' => ['required', 'regex:'.$latRegex], 
            'source_longitude' => ['required', 'regex:'.$longRegex], 
            'destination_address' => 'required|min:1|max:256', 
            'destination_latitude' => ['required', 'regex:'.$latRegex], 
            'destination_longitude' => ['required', 'regex:'.$longRegex], 
            'ride_distance' => 'required|numeric',
            'ride_time' => 'required|numeric',
            'estimated_fare' => 'required|regex:/^\d*(\.\d{1,2})?$/',
            'payment_mode' => 'required|in:'.implode(',', $this->rideRequest->getPaymentModes()),
        ]);


        if($validator->fails()) {
            $msg = [];
            foreach($validator->messages()->getMessages() as $key => $errArray) {
                $msg[$key] = $errArray[0];
            }

            return $this->api->json(false, 'VALIDATION_ERROR', 'Enter all the mandatory fields', $msg);
        }

        
        $rideRequest = new $this->rideRequest;
        $rideRequest->user_id = $request->auth_user->id;
        $rideRequest->ride_vehicle_type = $request->ride_vehicle_type;
        $rideRequest->source_address = $request->source_address;
        $rideRequest->source_latitude = $request->source_latitude;
        $rideRequest->source_longitude = $request->source_longitude;
        $rideRequest->destination_address = $request->destination_address;
        $rideRequest->destination_latitude = $request->destination_latitude;
        $rideRequest->destination_longitude = $request->destination_longitude;
        $rideRequest->ride_distance = $request->ride_distance / 1000; //converting meter into km
        $rideRequest->ride_time = $request->ride_time;
        $rideRequest->estimated_fare = $request->estimated_fare;
        $rideRequest->payment_mode = $request->payment_mode;
        $rideRequest->ride_status = Ride::INITIATED;


        /** calculation for coupon on if coupon passed*/
        if($request->coupon_code != '') {

            $validCoupon = $this->coupon->isValid($request->coupon_code, $request->auth_user->id, $coupon);
            if($validCoupon !== true) {
                return $this->api->json(false, $validCoupon['errcode'], $validCoupon['errmessage']);
            }

            $rideRequest->applied_coupon_id = $coupon->id;
        }
        
        /** calculation for coupon end*/


        /** finally save ride request record */
        $rideRequest->save();

        return $this->api->json(true, 'RIDE_REQUEST_INITIATED', 'Ride request initiated successfully', [
            'ride_request' => $rideRequest
        ]);
        
    }





    /**
     * give rating to driver and complete the request
     */
    public function rateDriver(Request $request)
    {

        //find ride request 
        $rideRequest = $this->rideRequest->where('id', $request->ride_request_id)
        ->where('user_id', $request->auth_user->id)
        ->whereIn('ride_status', [Ride::TRIP_ENDED, Ride::COMPLETED])
        ->first();


        if(!$rideRequest || !$rideRequest->driver) {
            return $this->api->json(false, 'INVALID_REQUEST', 'Invalid Request, Try again.');
        }

        //validate rating number
        if(!$request->has('rating') || !in_array($request->rating, Ride::RATINGS)) {
            return $this->api->json(false, 'INVALID_RATING', 'You must have give rating within '.implode(',', Ride::RATINGS));
        }

        //saving ride request rating
        $rideRequest->driver_rating = $request->rating;
        $rideRequest->save();


        /** push calcualte driver to job */
        ProcessDriverRating::dispatch($rideRequest->driver_id);

        return $this->api->json(true, 'RATED', 'Driver rated successfully.');

    }   





    /**
     * this returns ride request histories
     */
    public function getHistories(Request $request)
    {
        //takes completed, user cancelled, driver cancelled ride requests
        $rideRequests = $this->rideRequest->where('user_id', $request->auth_user->id)
        ->whereIn('ride_status', [Ride::COMPLETED, Ride::USER_CANCELED, Ride::DRIVER_CANCELED])
        ->with(['driver', 'invoice'])
        ->orderBy('updated_at', 'desc')
        ->paginate(500);


        $rideRequests->map(function($rideRequest){
            
            if($rideRequest->invoice) {
                $rideRequest->invoice['map_url'] = $rideRequest->invoice->getStaticMapUrl();
            }
            
            $rideRequest->driver['profile_photo_url'] = $rideRequest->driver->profilePhotoUrl();
        });

        return $this->api->json(true, 'RIDE_REQUEST_HISTORIES', 'Ride request histories', [
            'ride_requests'=> $rideRequests->items(),
            'paging' => [
                'total' => $rideRequests->total(),
                'has_more' => $rideRequests->hasMorePages(),
                'next_page_url' => $rideRequests->nextPageUrl()?:'',
                'count' => $rideRequests->count(),
            ]
        ]);


    }




    /**
     * razorpay initiate
     */
    public function initRazorpay(Request $request)
    {
        $rideRequest = $this->rideRequest->where('user_id', $request->auth_user->id)
        ->whereIn('ride_status', [Ride::TRIP_ENDED])
        ->where('payment_status', Ride::NOT_PAID)
        ->where('payment_mode', Ride::ONLINE)
        ->where('id', $request->ride_request_id)
        ->with(['invoice'])
        ->first();


        if(!$rideRequest) {
            return $this->api->json(false, 'INVALID_RIDE_REQUEST', 'Invalid ride request');
        }

        try {

            $razorpay = Gateway::instance('razorpay');
            $order = $razorpay->initiate($rideRequest->invoice->invoice_reference, $rideRequest->invoice->total * 100);

        } catch(\Exception $e) {
           $this->api->log('RAZORPAY_INIT_ERROR', $e->getMessage());
           return $this->api->unknownErrResponse();
        }
        

        return $this->api->json(true, 'RAZORPAY_INITIATED', 'Razorpay initiated', [
            'order_id' => $order->id,
            'razorpay_api_key' => $razorpay->publickeys()['RAZORPAY_API_KEY']
        ]);

    }




    /**
     * make razorpay payment
     */
    public function makeRazorpayPayment(Request $request)
    {
        $rideRequest = $this->rideRequest->where('id', $request->ride_request_id)
        ->where('user_id', $request->auth_user->id)
        ->whereIn('ride_status', [Ride::TRIP_ENDED])
        ->where('payment_status', Ride::NOT_PAID)
        ->with(['invoice'])
        ->first();

        if(!$rideRequest) {
            return $this->api->json(false, 'INVALID_RIDE_REQUEST', 'Invalid ride request');
        }

        $razorpay = Gateway::instance('razorpay');
        $data = $razorpay->charge($request);

        if(false === $data) {
            return $this->api->json(false, 'UNKOWN_ERROR', 'Unknown error. Try again or contact to service provider');
        }

        //check order receipt and invoice referecne same or not
        $orderReceipt = isset($data['extra']['order']['receipt']) ? $data['extra']['order']['receipt'] : '';
        if($orderReceipt != $rideRequest->invoice->invoice_reference) {
            return $this->api->json(false, 'UNKOWN_ERROR', 'Unknown error. Try again or contact to service provider');
        }


        try{
            DB::beginTransaction();

            $rideRequest->payment_status = Ride::PAID;
            $rideRequest->ride_status = Ride::COMPLETED;
            $rideRequest->save();

            $transaction = new $this->transaction;
            $transaction->trans_id = $data['transaction_id'];
            $transaction->amount = $data['amount'];
            $transaction->currency_type = $data['currency_type'];
            $transaction->gateway = $razorpay->gatewayName();   
            $transaction->extra_info = json_encode($data['extra']);
            $transaction->status = $data['status'];  
            $transaction->payment_method = $data['method'];
            $transaction->save();


            $invoice = $rideRequest->invoice;
            $invoice->transaction_table_id = $transaction->id;
            $invoice->payment_status = Ride::PAID;
            $invoice->save();
            
            DB::commit();
        } catch(\Exception $e) {
            DB::rollback();
            $this->api->log('RAZORPAY_CHARGE_ERROR', $e);
            return $this->api->json(false, 'UNKOWN_ERROR', 'Unknown error. Try again or contact to service provider');
        }


        //send invoice via email
        $this->email->sendUserRideRequestInvoiceEmail($rideRequest);
       

        /**
         * send push notification to user
         */
        $user = $request->auth_user;
        $currencySymbol = $this->setting->get('currency_symbol');
        $user->sendPushNotification("Payment successful", "{$currencySymbol}{$invoice->total} has been paid successfully");
        $user->sendSms("{$currencySymbol}{$invoice->total} has been paid successfully");


        /** send push to driver */
        $rideRequest->driver->sendPushNotification("User Paid", "User has paid {$currencySymbol}{$invoice->total} through online");


        return $this->api->json(true, 'PAID', 'Payment successful');

    }



    

}
