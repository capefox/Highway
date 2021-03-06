<?php

namespace App\Http\Controllers\Admin;

use App\Repositories\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RideFare;
use App\Models\Driver;
use Validator;
use App\Models\VehicleType;
use App\Models\Setting;


class Service extends Controller
{

    /**
     * init dependencies
     */
    public function __construct(RideFare $rideFare, Setting $setting, VehicleType $vehicleType, Api $api, Driver $driver)
    {
        $this->rideFare = $rideFare;
        $this->setting = $setting;
        $this->vehicleType = $vehicleType;
        $this->api = $api;
        $this->driver = $driver;
    }



    /**
     * show service lists
     */
    public function showServices()
    {
        $services = $this->vehicleType->allTypes();
        
        //sort by descdending created timestamp
        $services = collect($services)->sortByDesc('created_at')->toArray();

        //calculate count of each services by drivers
        foreach($services as $index => $service) {
            $services[$index]['used_by_driver'] = $this->driver->where('vehicle_type', $service['code'])->count();
        }

        $rideTaxPecentage = $this->setting->get('vehicle_ride_fare_tax_percentage');
        $cancellationCharge = $this->setting->get('ride_request_cancellation_charge');
        $cancellationChargeAfterMinute = $this->setting->get('ride_request_cancellation_charge_after_minute_trip_started');
        $driver_cancel_ride_request_limit = $this->setting->get('driver_cancel_ride_request_limit');
        $ride_request_driver_search_radius = $this->setting->get('ride_request_driver_search_radius');
        
        return view('admin.services', compact('services', 'rideTaxPecentage', 'cancellationCharge', 'cancellationChargeAfterMinute', 'driver_cancel_ride_request_limit', 'ride_request_driver_search_radius'));
    }





    /**
     * save driver cancel ride request limit
     */
    public function saveDriverCancelRideRequestLimit(Request $request)
    {
        $this->setting->set('driver_cancel_ride_request_limit', $request->driver_cancel_ride_request_limit);
        return $this->api->json(true, 'SAVED', 'Driver cancel ride request limit set');
    }


    public function saveDriverSearchRadius(Request $request)
    {
        $this->setting->set('ride_request_driver_search_radius', $request->ride_request_driver_search_radius);
        return $this->api->json(true, 'SAVED', 'Driver search radius saved.');
    }





    /**
     * save ride request cancellation charge
     */
    public function saveRideRequestCancellationCharge(Request $request)
    {
        $this->setting->set('ride_request_cancellation_charge', $request->ride_request_cancellation_charge);
        $this->setting->set('ride_request_cancellation_charge_after_minute_trip_started', $request->ride_request_cancellation_charge_after_minute_trip_started);
        return $this->api->json(true, 'SAVED', 'Cancellation charge saved successfully');
    }


    /**
     * save ride tax percentage
     */
    public function saveRideTaxPercentage(Request $request)
    {
        if($request->tax_percentage < 0) {
            return $this->api->json(false, 'DELETED', 'Failed to save service tax percentage');
        }

        $this->setting->set('vehicle_ride_fare_tax_percentage', $request->tax_percentage);
        return $this->api->json(true, 'SAVED', 'Service tax percentage saved successfully');
    }



    /**
     * add new service type
     */
    public function addService(Request $request)
    {

        //validation service name
        if($request->service_name == '' || !in_array($request->_action, ["update", 'add', 'delete', 'set_order', 'enable_highway', 'activate'])) {
            return $this->api->json(false, 'MISSING_PARAMTERS', 'Missing parameters');
        }

        switch ($request->_action) {

            case 'enable_highway' : 

                $this->vehicleType->enableHighway($request->service_id, $request->enable == 'true');            
                return $this->api->json(true, 'UPDATED', 'Service highway enable status updated successfully');
                break;

            case 'activate' : 

                $this->vehicleType->activate($request->service_id, $request->enable == 'true');            
                return $this->api->json(true, 'UPDATED', $request->enable == 'true' ? "Service enabled." : "Service disabled.");
                break;


            case 'set_order' : 

                $this->vehicleType->setOrder($request->service_id, $request->order);            
                return $this->api->json(true, 'UPDATED', 'Service order updated successfully');
                break;

            case 'add':
                
                $error = '';
                $serviceType = $this->vehicleType->addType($request->service_code, $request->service_name, $request->service_description, $error);

                //check if service already exists
                if($serviceType === false && $error == 'EXISTS') {
                    return $this->api->json(false, 'EXISTS', 'Service or code already exists');
                }

                return $this->api->json(true, 'ADDED', 'Service created successfully', [
                    'service_type' => $serviceType
                ]);
            
                break;

            case 'update':
                $error = '';
                $serviceType = $this->vehicleType->find($request->service_id);                
                $serviceType = $serviceType->updateServiceType($request->service_name, $request->service_description, $error);
                
                //check if service already exists
                if($serviceType === false && $error == 'EXISTS') {
                    return $this->api->json(false, 'EXISTS', 'Service name already exists for another');
                }

                return $this->api->json(true, 'UPDATED', 'Service updated successfully', [
                    'service_type' => $serviceType
                ]);
            
                break;


            case 'delete':
                
                \DB::beginTransaction();
                try{

                    $this->vehicleType->where('id', $request->service_id)->forceDelete();
                    $this->rideFare->where('vehicle_type_id', $request->service_id)->forceDelete();
                    /** updating cache */
                    VehicleType::updateServicesCache();

                    \DB::commit();

                } catch(\Exception $e) {
                    \DB::rollback();
                    $this->api->Log('ADMIN_SERVICE_DEL_ERROR', $e->getMessage());
                    return $this->api->json(false, 'INTERNAL_SERVER_ERROR', 'Internal server error try again.');
                }
                
                return $this->api->json(true, 'DELETED', 'Service deleted successfully');
            
                break;

        }

        


    }



    /**
     * get intracity ride fares
     */
    public function getRideFare(Request $request)
    {
       
        $rideFare = $this->rideFare->where('vehicle_type_id', $request->service_id)->first();
        //ride fare not found
        if(!$rideFare) {
            return $this->api->json(false, "NOT_FOUND", 'Ride fare not found');
        }

        return $this->api->json(true, 'RIDE_FARE', 'Ride fare fetched', [
            'ride_fare' => $rideFare
        ]);

    }





   
    /**
     * update ride fare for a particular service type
     */
    public function createOrUpdateRideFare(Request $request)
    {
        //validate service id
        if(!collect($this->vehicleType->allTypes())->where('id', $request->service_id)->first()) {
            return $this->api->json(false, 'INVALID_SERVICE', 'Invalid service');
        }

        //create or update ride fare
        $rideFare = $this->rideFare->addOrUpdateRideFare($request->service_id, [
            'minimun_price' => $request->minimun_price,
            'access_fee' => $request->access_fee,
            'base_price' => $request->base_price,
            'first_distance' => $request->first_distance,
            'first_distance_price' => $request->first_distance_price,
            'after_first_distance_price' => $request->after_first_distance_price,
            'wait_time_price' => $request->wait_time_price,
            'cancellation_fee' => $request->cancellation_fee,
        ]);

        return $this->api->json(true, 'RIDE_FARE', 'Ride fare created or updated', [
            'ride_fare' => $rideFare,
        ]);

    }




}


