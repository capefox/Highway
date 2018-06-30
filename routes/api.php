<?php


/**
 * routes for user api
 * routes are alredy prefixed by '/api'
 */
Route::group(['prefix' => '/v1/user'], function(){

    /**
     * password reset apis
     */
    Route::post('password-reset-request', 'Apis\User\UserRegister@sendPasswordReset');
    Route::post('password-reset', 'Apis\User\UserRegister@resetPassword');

    Route::post('register', 'Apis\User\UserRegister@doRegister');
    Route::post('login', 'Apis\User\UserRegister@doLogin');
    
    Route::get('vehicle-types', 'Apis\User\UserRegister@getVehicleTypes');

    //social login routes
    Route::post('facebook', 'Apis\User\Facebook@authenticate');
    Route::post('google', 'Apis\User\Google@authenticate');

    Route::group(['prefix' => 'referral'], function(){
        Route::get('verify', 'Apis\User\Referral@verifyReferralCode');
    });


    /** trip user authorization removed apis */
    Route::get('trips/source-destination-list', 'Apis\User\Trip@getSourceDestList');
    /** end trip user authorization removed apis */




    Route::group(['middleware' => 'userApiAuth'], function(){

        Route::post('push-token/update', 'Apis\User\UserProfile@updatePushToken');
        Route::post('otp/send', 'Apis\User\UserRegister@sendOtp');
        Route::post('otp/verify', 'Apis\User\UserRegister@verifydOtp');

        Route::get('profile', 'Apis\User\UserProfile@getUserProfile');
        Route::post('profile/update', 'Apis\User\UserProfile@updateUserProfile');

        Route::post('estimate-price/{vehicle_type_id}', 'Apis\User\PriceCalculator@estimatePrice');

        Route::get('nearby-drivers/{lat_long}/{vehicle_type?}', 'Apis\User\RideRequest@getNearbyDrivers');

        Route::get('ride-request/payment-modes', 'Apis\User\RideRequest@getPaymentModes');
        Route::post('ride-request/update-payment-mode', 'Apis\User\RideRequest@updatePaymentMode');
        Route::get('ride-request/check', 'Apis\User\RideRequest@checkRideRequest');
        Route::post('ride-request/{ride_request_id}/cancel', 'Apis\User\RideRequest@cancelRideRequest');
        Route::post('ride-request/initiate', 'Apis\User\RideRequest@initiateRideRequest');
        Route::post('ride-request/{ride_request_id}/rate-driver', 'Apis\User\RideRequest@rateDriver');
        Route::get('ride-requests/histories', 'Apis\User\RideRequest@getHistories')->name('ride_request_histories');


        /**
         * rasorpay payment apis
         */
        Route::post('ride-requests/{ride_request_id}/razorpay/init', 'Apis\User\RideRequest@initRazorpay');
        Route::post('ride-requests/{ride_request_id}/razorpay/pay', 'Apis\User\RideRequest@makeRazorpayPayment');



        Route::group(['prefix' => 'referral'], function(){
            Route::get('info', 'Apis\User\Referral@getReferralInfo');
        });





        /**
         * intercity trip routes
         */
        Route::group(['prefix' => 'trips'], function(){

            Route::get('search', 'Apis\User\Trip@searchTrips');
            Route::post('book', 'Apis\User\Trip@bookTrip');
            Route::get('booked', 'Apis\User\Trip@getBookedTrips');
            Route::get('booking/{booking_id}/razorpay/init', 'Apis\User\Trip@initRazorpay');
            Route::post('booking/{booking_id}/razorpay/pay', 'Apis\User\Trip@makeRazorpayPayment');
            /*
            
            
            Route::post('{trip_id}/trip_routes/{trip_route_id}/driver/rating/{rating}', 'Apis\User\Trip@rateTripDriver');
            Route::get('histories', 'Apis\User\Trip@getHistories')->name('user.trip_histories'); */
            /**
             * rasorpay payment apis
             */
            /* 
             */

        }); 


    });




});



/**
 * routes for driver apis
 * routes are alredy prefixed by '/api'
 */
Route::group(['prefix' => '/v1/driver'], function(){

    /**
     * password reset apis
     */
    Route::post('password-reset-request', 'Apis\Driver\DriverAuth@sendPasswordReset');
    Route::post('password-reset', 'Apis\Driver\DriverAuth@resetPassword');

    Route::post('register', 'Apis\Driver\DriverAuth@doRegister');
    Route::post('login', 'Apis\Driver\DriverAuth@doLogin');
    Route::get('vehicle-types', 'Apis\Driver\DriverAuth@getVehicleTypes');


    //social login routes
    Route::post('facebook', 'Apis\Driver\Facebook@authenticate');
    Route::post('google', 'Apis\Driver\Google@authenticate');


    /** get all admin created routes */
    Route::get('trips/routes', 'Apis\Driver\Trip@getTripRoutes');


    Route::group(['prefix' => 'referral'], function(){
        Route::get('verify', 'Apis\Driver\Referral@verifyReferralCode');
    });


    //driver's authenticated routes
    Route::group(['middleware' => 'driverApiAuth'], function(){

        Route::post('otp/send', 'Apis\Driver\DriverAuth@sendOtp');
        Route::post('otp/verify', 'Apis\Driver\DriverAuth@verifydOtp');

        Route::post('push-token/update', 'Apis\Driver\DriverProfile@updatePushToken');
        Route::get('profile', 'Apis\Driver\DriverProfile@getDriverProfile');
        Route::post('profile/update', 'Apis\Driver\DriverProfile@updateDriverProfile');

        Route::get('ride-request/check', 'Apis\Driver\RideRequest@checkRideRequest');
        Route::post('ride-request/accept', 'Apis\Driver\RideRequest@acceptRideRequest');
        Route::post('ride-request/{ride_request_id}/cancel', 'Apis\Driver\RideRequest@cancelRideRequest');
        Route::post('ride-request/{ride_request_id}/change-ride-status', 'Apis\Driver\RideRequest@changeRideRequestStatus');
        Route::post('ride-request/{ride_request_id}/start-trip', 'Apis\Driver\RideRequest@startRideRequest');
        Route::post('ride-request/{ride_request_id}/end-trip', 'Apis\Driver\RideRequest@endRideRequest');
        Route::post('ride-request/{ride_request_id}/rate-user', 'Apis\Driver\RideRequest@rateUser');

        Route::get('ride-requests/histories', 'Apis\Driver\RideRequest@getHistories')->name('ride_request_histories');



        Route::group(['prefix' => 'referral'], function(){
            Route::get('info', 'Apis\Driver\Referral@getReferralInfo');
        });


        /**
         * intercity trip routes
         */
        Route::group(['prefix' => 'trips'], function(){

            Route::post('create', 'Apis\Driver\Trip@createTrip');

            /* Route::get('/', 'Apis\Driver\Trip@getTrips');
            
            Route::post('{trip_id}/delete', 'Apis\Driver\Trip@deleteTrip');
            Route::get('{trip_id}/details', 'Apis\Driver\Trip@getTripDetails');
            Route::post('{trip_id}/driver_started', 'Apis\Driver\Trip@driverStartedTrip');
            Route::post('{trip_id}/trip_points/{trip_point_id}/driver_reached', 'Apis\Driver\Trip@driverReachedTripPoint');
            Route::post('{trip_id}/trip_points/{trip_point_id}/start_trip', 'Apis\Driver\Trip@driverStartTripPoint');
            Route::post('{trip_id}/users/rate', 'Apis\Driver\Trip@driverGiveRatingToBookedUsers');
            Route::post('{trip_id}/complete', 'Apis\Driver\Trip@completeTrip');
            Route::get('histories', 'Apis\Driver\Trip@getTripHistories')->name('driver.trip_histories'); */
        }); 



    });



});
