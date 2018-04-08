<?php

namespace App\Repositories;

use App\Repositories\Gateway;
use App\Models\Setting;
use Razorpay\Api\Api;


class RazorPay extends Gateway
{


	public function __construct(Setting $setting)
	{
		$this->setting = $setting;
	}


	public function publickeys()
	{
		return [
            'RAZORPAY_API_KEY' => $this->setting->get('RAZORPAY_API_KEY')
        ];
	}



	public function allKeys()
	{
		return [
            'RAZORPAY_API_KEY' => $this->setting->get('RAZORPAY_API_KEY'), 
            'RAZORPAY_API_SECRET' => $this->setting->get('RAZORPAY_API_SECRET')
        ];
	}


	public function gatewayName()
	{
		return 'RAZORPAY';
    }
    

    /**
     * initiate request
     */
    public function initiate($receipt, $amount, $currency =  'INR')
    {
        $api = new Api(
            $this->allKeys()['RAZORPAY_API_KEY'],
            $this->allKeys()['RAZORPAY_API_SECRET']
        );

        $order = $api->order->create(array('receipt' => $receipt, 'amount' => $amount, 'currency' => $currency)); // Creates order
        
        return $order;
    }



	public function charge($request)
	{
        $api = new Api(
            $this->allKeys()['RAZORPAY_API_KEY'],
            $this->allKeys()['RAZORPAY_API_SECRET']
        );

        try {

            $payment  = $api->payment->fetch($request->payment_id);
            $order = $api->order->fetch($payment->order_id);


            //if payment orderid and reuest pasded order id not same
            if($payment->order_id != $request->order_id) {
                return false;
            }
        
            if($payment->status == 'authorized') {
                $payment = $payment->capture(array('amount'=> $order->amount));
            }
            
            return [
                'success'        => true,
                'transaction_id' => $payment->id,
                'status'         => 'captured',
                'amount'         => $payment->amount / 100,
                'method'         => $payment->method,
                'currency_type'  => $payment->currency,
                'extra'          => [
                    'payment' => $payment->toArray(),
                    'order' => $order->toArray()
                ]
            ];


        } catch(\Exception $e) {
            \Log::info('RAZORPAY_CHARGE_ERROR');
            \Log::info($e->getMessage());
            return false;
        }

        
      
	}



}