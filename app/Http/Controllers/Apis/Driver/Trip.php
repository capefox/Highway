<?php

namespace App\Http\Controllers\Apis\Driver;

use App\Repositories\Api;
use App\Http\Controllers\Controller;
use DB;
use App\Repositories\Email;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Repositories\SocketIOClient;
use App\Models\Trip as TripModel;
use App\Models\TripPoint;
use App\Models\TripRoute;
use App\Repositories\Utill;
use App\Models\VehicleType;
use App\Models\RideFare;
use Validator;

class Trip extends Controller
{

    /**
     * init dependencies
     */
    public function __construct(
        TripRoute $tripRoute,
        RideFare $rideFare, 
        VehicleType $vehicleType, 
        TripPoint $tripPoint, 
        TripModel $trip, 
        Utill $utill, 
        Setting $setting, 
        Email $email, 
        Api $api, 
        SocketIOClient $socketIOClient
    )
    {
        $this->tripRoute = $tripRoute;
        $this->rideFare = $rideFare;
        $this->vehicleType = $vehicleType;
        $this->tripPoint = $tripPoint;
        $this->trip = $trip;
        $this->utill = $utill;
        $this->setting = $setting;
        $this->email = $email;
        $this->api = $api;
        $this->socketIOClient = $socketIOClient;
    }


    /**
     * create trip for driver 
     * create points, point orders
     * calculate affected routes
     * use algoright for consecutive sub array
     */
    public function createTrip(Request $request)
    {
        //dd($request->all());
        /* //validate trip create request       
        $validator = Validator::make(
            $request->all(), $this->trip->createTripValidationRules($request)
        );

        //if validation fails
        if($validator->fails()) {
            
            $errors = [];
            foreach($validator->errors()->getMessages() as $fieldName => $msgArr) {
                $errors[$fieldName] = $msgArr[0];
            }
            return $this->api->json(false, 'VALIDATION_ERROR', 'Fill all the fields before create trip', [
                'errors' => $errors
            ]);
        } */


        /**
         * get ride fare for further ride calculation
         */
        $vTypeId = $this->vehicleType->getIdByCode($request->auth_driver->vehicle_type);
        $rideFare = $this->rideFare->where('vehicle_type_id', $vTypeId)->first();

        $trip = new $this->trip;
        $trip->driver_id = $request->auth_driver->id;
        $trip->name = ucfirst(trim($request->trip_name));
        $trip->no_of_seats = $request->seats;
        $trip->date_time = $this->utill->timestampStringToUTC($request->trip_date_time, $request->auth_driver->timezone)->toDateTimeString();
        $trip->status = TripModel::INITIATED;

        try {

            DB::beginTransaction();
            
            $trip->save();

            //saving trip points
            $tripPoints = [];
            /* $tripRoute */
            $order = 1;
            foreach($request->points as $point) {
                $tripPoint = new $this->tripPoint;
                $tripPoint->trip_id = $trip->id;
                $tripPoint->order = $order++;
                $tripPoint->address = $point['address'];
                $tripPoint->latitude = $point['latitude'];
                $tripPoint->longitude = $point['longitude'];
                $tripPoint->distance = isset($point['distance']) ? $point['distance'] / 1000 : 0;
                $tripPoint->time = isset($point['time']) ? $point['time'] : 0;
                $tripPoint->save(); 
                $tripPoints[] = $tripPoint;
            }
        
            /**
             * finding all possible routes and distance, time estimated
             */
            $tripRoutes = [];
            $pointsCount = count($tripPoints);
            $scaleSize = 2;
            while($scaleSize <= $pointsCount) {

                $scaleStartIndex = 0;
                $scaleEndIndex = $scaleStartIndex + $scaleSize - 1;

                while($scaleEndIndex < $pointsCount) {
        
                    $time = 0;
                    $distance = 0;
                    for($i = $scaleStartIndex; $i < $scaleEndIndex; $i++) {
            
                        $tripPoint = $tripPoints[$i + 1];
                        $time += $tripPoint->time;
                        $distance += $tripPoint->distance;
                    }

                    /**
                     * save trip routes in database
                     */
                    //echo "{$tripPoints[$scaleStartIndex]->address} -  {$tripPoints[$scaleEndIndex]->address} $time $distance<br>";
                    $tripRoute = new $this->tripRoute;
                    $tripRoute->trip_id = $trip->id;
                    $tripRoute->start_point_address = $tripPoints[$scaleStartIndex]->address;
                    $tripRoute->start_point_latitude = $tripPoints[$scaleStartIndex]->latitude;
                    $tripRoute->start_point_longitude = $tripPoints[$scaleStartIndex]->longitude;
                    $tripRoute->start_point_order = $tripPoints[$scaleStartIndex]->order;
                    $tripRoute->end_point_address = $tripPoints[$scaleEndIndex]->address;
                    $tripRoute->end_point_latitude = $tripPoints[$scaleEndIndex]->latitude;
                    $tripRoute->end_point_longitude = $tripPoints[$scaleEndIndex]->longitude;
                    $tripRoute->end_point_order = $tripPoints[$scaleEndIndex]->order;
                    $tripRoute->seat_affects = '';
                    $tripRoute->seats_available = $trip->no_of_seats;
                    $tripRoute->estimated_distance = $distance;
                    $tripRoute->estimated_time = $time;

                    //calculate fare
                    $fare = $rideFare->calculateFare($distance, $time);
                    $tripRoute->estimated_fare = $fare['total'];

                    $tripRoute->save();
                    $tripRoutes[] = $tripRoute;
                    

                    $scaleStartIndex++;
                    $scaleEndIndex++;

                }

                $scaleSize++;

            }


            /**
             * calculate seat affects and save
             */
            $this->tripRoute->calculateSeatAffects($tripRoutes);
         
        
            DB::commit();

        } catch(\Exception $e) {
            DB::rollback();
            $this->api->log('CREATE_TRIP_ERROR', $e->getMessage());
            return $this->api->unknownErrResponse(['error_text', $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()]);
        }
        
        
        
        return $this->api->json(true, "TRIP_CREATED", 'Trip created', [
            'trip' => $trip,
            'trip_points' => $tripPoints,
            'trip_routes' => $tripRoutes
        ]);
        

    }




    /**
     * delete a particular trip if only if trip status is initiated
     */
    public function deleteTrip(Request $request)
    {
        //fetching correct trip to delete
        $trip = $this->trip->where('id', $request->trip_id)
        ->where('driver_id', $request->auth_driver->id)
        ->where('trip_status', TripModel::INITIATED)
        ->first();

        //if no trip found means driver not allowed to delete
        if(!$trip) {
            return $this->api->json(false, "INVALID", 'You are not allowed to delete this trip any more. You can cancel it.');
        }


        try {

            DB::beginTransaction();
            
            $this->trip->deleteTrip($trip->id);
            
            DB::commit();

        } catch(\Exception $e) {
            DB::rollback();
            $this->api->log('DELETE_TRIP_ERROR', $e->getMessage());
            return $this->api->unknownErrResponse(['error_text', $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()]);
        }

        return $this->api->json(false, 'TRIP_DELETED', 'Trip deleted');
        
    }




    /**
     * delete trip pickup point
     * if trip point is the parent trip point then not supposed to delete
     * rather ask driver to delete whole trip
     */
    public function deleteTripPickupPoint(Request $request)
    {
        //fetching correct trippoint to delete
        $tripTable = $this->trip->getTableName();
        $tripPoint = $this->tripPoint->join($tripTable, "{$tripTable}.id", '=', "{$this->tripPoint->getTableName()}.trip_id")
        ->where("{$tripTable}.id", $request->trip_id)
        ->where("{$tripTable}.driver_id", $request->auth_driver->id)
        ->where("{$this->tripPoint->getTableName()}.trip_points_parent_id", '<>', 0)
        ->where("{$this->tripPoint->getTableName()}.id", '=', $request->pickup_point_id)
        ->where("{$this->tripPoint->getTableName()}.trip_status", TripModel::INITIATED)
        ->select("{$this->tripPoint->getTableName()}.*")
        ->first();

        if(!$tripPoint) { 
            return $this->api->json(false, "INVALID", 'You are not allowed to delete this trip point.');
        }

        //fetching corrent trip point
        $tripPoint->forceDelete();

        return $this->api->json(false, 'TRIP_POINT_DELETED', 'Trip point deleted');


    }




    /**
     * add new pickup point
     */
    public function addPickupPoint(Request $request)
    {

        //validate trip create request       
        $validator = Validator::make(
            $request->all(), $this->tripPoint->keyRules()
        );

        //if validation fails
        if($validator->fails()) {
            
            $errors = [];
            foreach($validator->errors()->getMessages() as $fieldName => $msgArr) {
                $errors[$fieldName] = $msgArr[0];
            }
            return $this->api->json(false, 'VALIDATION_ERROR', 'Fill all the fields before create trip', [
                'errors' => $errors
            ]);
        }


        //find correct trip
        $trip = $this->trip->where('id', $request->trip_id)
        ->where('driver_id', $request->auth_driver->id)
        ->first();


        //if no trip found means driver not allowed to add trip
        if(!$trip) {
            return $this->api->json(false, "INVALID", 'You are not allowed to add new pickup pionts to this');
        }

        //find trip parent pickup point
        $tripPointParent = $this->tripPoint->where('trip_id', $trip->id)
        ->where('trip_points_parent_id', 0)
        ->first();


        $tripPoint = new $this->tripPoint;
        $tripPoint->trip_id = $trip->id;
        $tripPoint->trip_points_parent_id = $tripPointParent->id;
        $tripPoint->seats_booked = 0;
        $tripPoint->source_address = trim($request->address);
        $tripPoint->source_latitude = $request->latitude;
        $tripPoint->source_longitude = $request->longitude;
        $tripPoint->destination_address = $tripPointParent->destination_address;
        $tripPoint->destination_latitude = $tripPointParent->destination_latitude;
        $tripPoint->destination_longitude = $tripPointParent->destination_longitude;
        $tripPoint->estimated_trip_time = $request->estimated_trip_time;
        $tripPoint->estimated_trip_distance = $request->estimated_trip_distance;

        /**
         * get ride fare for further ride calculation
         */
        $vTypeId = $this->vehicleType->getIdByCode($request->auth_driver->vehicle_type);
        $rideFare = $this->rideFare->where('vehicle_type_id', $vTypeId)->first();

        $fare = $rideFare->calculateFare($request->estimated_trip_distance, $request->estimated_trip_time);
        $tripPoint->estimated_fare = $fare['total'];

        $tripPoint->trip_status = TripModel::INITIATED;
        $tripPoint->save();      

        return $this->api->json(true, 'TRIP_POINT_ADDED', 'Trip point Added', [
            'trip_point' => $tripPoint
        ]);

    }





    /**
     * get all trips those are not completed
     */
    public function getTrips(Request $request)
    {

        $trips = $this->trip
        ->with('pickupPoints', 'pickupPoints.userBookings', 'pickupPoints.userBookings.user')
        ->where('driver_id', $request->auth_driver->id)
        ->whereNotIn('trip_status', [TripModel::COMPLETED, TripModel::TRIP_CANCELED]);

        
        $dateRange = app('UtillRepo')->utcDateRange($request->date, $request->auth_driver->timezone);

        if(is_array($dateRange)) {
            $trips = $trips->whereBetween('trip_date_time', $dateRange);
        }
        //else take all trips after current date 
        else{
            $trips = $trips->where('trip_date_time', '>=', date('Y-m-d H:i:s'));
        }

        $trips = $trips->get();

        return $this->api->json(true, 'TRIPS', 'Your trips', [
            'trips' => $trips
        ]);

    }




}
