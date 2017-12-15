<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {


        /**
         * make App\Repositories\Utill singleton
         */
        $this->app->singleton('UtillRepo', function ($app) {
            return $app->make('App\Repositories\Utill');
        });


        $this->setting = app('App\Models\Setting');
        $this->setEmailSettings();
    }

    /**
    *   This code will set Email config taking information from settings 
    */  
    protected function setEmailSettings()
    {

        $driver = $this->setting->get('mail_driver');
        
        if ($driver == 'mandrill') {

            config([
                'mail.driver'              => 'mandrill',
                'mail.host'                => $this->setting->get('mandril_host'),
                'mail.port'                => intval($this->setting->get('mandril_port')),
                'mail.username'            => $this->setting->get('mandril_username'),   
                'services.mandrill.secret' => $this->setting->get('mandril_secret')
            ]);


        } else if ($driver == 'smtp') {

            config([
                'mail.driver'       => 'smtp',
                'mail.host'         => $this->setting->get('smtp_host'),
                'mail.port'         => intval($this->setting->get('smtp_port')),
                'mail.username'     => $this->setting->get('smtp_username'),
                'mail.password'     => $this->setting->get('smtp_password'),
                'mail.encryption'   => $this->setting->get('smtp_encryption')
            ]);

        } else if($driver == 'mailgun') {


             config([
                'mail.driver'       => 'mailgun',
                'mail.host'         => $this->setting->get('mailgun_host'),
                'mail.port'         => intval($this->setting->get('mailgun_port')),
                'mail.username'     => $this->setting->get('mailgun_username'),
                'mail.password'     => $this->setting->get('mailgun_password'),
            ]);


            config([
                'services.mailgun.domain' => $this->setting->get('mailgun_domain'),
                'services.mailgun.secret' => $this->setting->get('mailgun_secret')
            ]);


        }

    }


}
