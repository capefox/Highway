<?php

namespace App\Http\Controllers\Apis\User;

use App\Repositories\Api;
use App\Http\Controllers\Controller;
use DB;
use App\Models\Driver;
use Hash;
use Illuminate\Http\Request;
use App\Models\RideRequest as Ride;
use App\Models\Setting;
use App\Repositories\SocketIOClient;
use App\Repositories\Utill;
use Validator;
use App\Models\VehicleType;

class RideRequest extends Controller
{

    /**
     * init dependencies
     */
    public function __construct(SocketIOClient $socketIOClient, Utill $utill, Setting $setting, Api $api, Ride $rideRequest, VehicleType $vehicleType, Driver $driver)
    {
        $this->socketIOClient = $socketIOClient;
        $this->utill = $utill;
        $this->setting = $setting;
        $this->api = $api;
        $this->rideRequest = $rideRequest;
        $this->vehicleType = $vehicleType;
        $this->driver = $driver;
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
     * updates ride request payment mode
     * payment modes can be updated before driver accepted the request
     */
    public function updatePaymentMode(Request $request)
    {
        //if payment mode does not match 
        if(!in_array($request->payment_mode, $this->rideRequest->getPaymentModes())) {
            return $this->api->json(false, 'INVALID_PAYMENT_MODE', 'Invalid payment mode selected');
        }

        /**
         *  if request_id in invalid or request not belongs to user
         *  or request status is not allowed to change the payment status
         */
        $rideRequest = $this->rideRequest->where('id', $request->ride_request_id)
        ->whereIn('ride_status', $this->rideRequest->updatePaymentModeAllowedStatusList())
        ->first();

        if(!$rideRequest) {
            return $this->api->json(false, 'INVALID_RIDE_REQUEST', 'Invalid ride request'); 
        }

        $rideRequest->payment_mode = $request->payment_mode;
        $rideRequest->save();

        return $this->api->json(true, 'PAYMENT_MODE_UPDATED', 'Payment mode updated successfully'); 


    }







    /**
     * check previous on going ride request
     * if payment not done, not completed, rating not given etc etc
     */
    public function checkRideRequest(Request $request)
    {
        
        $rideRequest = $this->rideRequest
        ->where('user_id', $request->auth_user->id)
        ->whereNotIn('ride_status', $this->rideRequest->notOngoigRideRequestStatusList())
        ->orWhere('driver_rating', 0)
        ->first();

        if(!$rideRequest) {
            return $this->api->json(false, 'NO_ONGOING_REQUEST_FOUND', 'No ongoing request');
        }
        

        $driver = [
            'id' => $rideRequest->driver->id,
            'fname' => $rideRequest->driver->fname,
            'lname' => $rideRequest->driver->lname,
            'country_code' => $rideRequest->driver->country_code,
            'mobile_number' => $rideRequest->driver->mobile_number,
            'latidue' => $rideRequest->driver->latitude,
            'longitude' => $rideRequest->driver->longitude,
        ];

        //take invoice if invoice is ready
        $invoice = []; //init invoice array empty
        if($rideRequest->ride_invoice_id != 0) {
            $invoice = $rideRequest->invoice->toArray();
            unset($rideRequest->invoice);
        }

        //removing driver object relationship from ride request
        unset($rideRequest->driver);

        return $this->api->json(true, 'ONGOING_REQUEST', 'Ongoing request found', [
            'ride_request' => $rideRequest,
            'driver' => $driver,
            'invoice' => $invoice,
        ]);


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
        $driver->sendPushNotification("User {$request->auth_user->fname} has canceled your ride request", $notificationData);


        /**
         * send socket push to driver
         */
        $this->socketIOClient->sendEvent([
            'to_ids' => $rideRequest->user_id,
            'entity_type' => 'driver', //socket will make it uppercase
            'event_type' => 'ride_request_status_changed',
            'data' => $notificationData
        ]);



        return $this->api->json(true, 'RIDE_REQUEST_CANCELED', 'Ride Request canceled successfully'); 
           
    }




    /**
     * get nearby drivers 
     * used for ride reqeust basically ?vehicle_type is optional
     */
    public function getNearbyDrivers(Request $request)
    {

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
        

        $radious = $this->setting->get('ride_request_driver_search_radious')?:0;
        $drivers = $this->driver->getNearbyDriversBuilder($latitude, $longitude, $radious);
        
        //if vehicle type is passed then filter drivers
        if($request->vehicle_type) {
            $drivers = $drivers->where($this->driver->getTableName().'.vehicle_type', $request->vehicle_type);
        }

        //filter drivers approved, available, connected to socket
        $dt = $this->driver->getTableName();
        $nearbyDriversDetails = $drivers->where($dt.'.is_approved', 1)
        ->where($dt.'.is_available', 1)
        ->where($dt.'.is_connected_to_socket', 1)
        ->orderBy($dt.'.rating', 'desc')
        ->select([
            $dt.'.id',            
            $dt.'.latitude',            
            $dt.'.longitude',
            $dt.'.vehicle_type',            
        ])
        ->take(50)->get();

        return $this->api->json(true, 'NEARBY_DRIVERS', 'Nearby drivers', [
            'drivers' => $nearbyDriversDetails
        ]);

    }






    /**
     * initiate ride requet 
     * check if ongoing request is there dont allow to request
     */
    public function initiateRideRequest(Request $request)
    {

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


        //updatig both driver and ride request table
        try {

            list($ratingValue, $driverRating) = $rideRequest->calculateDriverRating($request->rating);

            \DB::beginTransaction();

            //saving ride request rating
            $rideRequest->driver_rating = $ratingValue;
            $rideRequest->save();  

            //saving driver rating
            $driver = $rideRequest->driver;
            $driver->rating = $driverRating;
            $driver->save();

            \DB::commit();

        } catch(\Exception $e) {
            \DB::rollback();
            \Log::info('DRIVER_RATING');
            \Log::info($e->getMessage());
            return $this->api->unknownErrResponse();
        }
        

        return $this->api->json(true, 'RATED', 'Driver rated successfully.');

    }   


    

}
