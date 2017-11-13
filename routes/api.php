<?php


/**
 * routes for user api
 * routes are alredy prefixed by '/api'
 */
Route::group(['prefix' => '/v1/user'], function(){

    Route::post('register', 'Apis\User\UserRegister@doRegister');
    Route::post('login', 'Apis\User\UserRegister@doLogin');

    //social login routes
    Route::post('facebook', 'Apis\User\Facebook@authenticate');
    Route::post('google', 'Apis\User\Google@authenticate');


    Route::group(['middleware' => 'userApiAuth'], function(){

        Route::post('otp/send', 'Apis\User\UserRegister@sendOtp');
        Route::post('otp/verify', 'Apis\User\UserRegister@verifydOtp');

    });




});



/**
 * routes for driver apis
 * routes are alredy prefixed by '/api'
 */
Route::group(['prefix' => '/v1/driver'], function(){

    Route::post('register', 'Apis\Driver\DriverAuth@doRegister');
    Route::post('login', 'Apis\Driver\DriverAuth@doLogin');
    Route::get('vehicle-types', 'Apis\Driver\DriverAuth@getVehicleTypes');


    //social login routes
    Route::post('facebook', 'Apis\Driver\Facebook@authenticate');
    Route::post('google', 'Apis\Driver\Google@authenticate');


    //driver's authenticated routes
    Route::group(['middleware' => 'driverApiAuth'], function(){

        Route::post('otp/send', 'Apis\Driver\DriverAuth@sendOtp');
        Route::post('otp/verify', 'Apis\Driver\DriverAuth@verifydOtp');

    });



});
