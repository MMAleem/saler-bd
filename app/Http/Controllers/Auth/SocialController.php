<?php
/**
 * LaraClassified - Geo Classified Ads CMS
 * Copyright (c) BedigitCom. All Rights Reserved
 *
 * Website: http://www.bedigit.com
 *
 * LICENSE
 * -------
 * This software is furnished under a license and may be used and copied
 * only in accordance with the terms of such license and with the inclusion
 * of the above copyright notice. If you Purchased from Codecanyon,
 * Please read the full License from here - http://codecanyon.net/licenses/standard
 */

namespace App\Http\Controllers\Auth;

use App\Helpers\Ip;
use App\Http\Controllers\FrontController;
use App\Mail\UserNotification;
use App\Models\Post;
use App\Models\User;
use App\Models\Scopes\ReviewedScope;
use App\Models\Scopes\VerifiedScope;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

class SocialController extends FrontController
{
    use AuthenticatesUsers;
	
	// If not logged in redirect to
	protected $loginPath = 'login';
	
	// After you've logged in redirect to
    protected $redirectTo = 'account';
    
    // Supported Providers
    private $network = ['facebook', 'google', 'twitter'];
    
    /**
     * SocialController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        
        // Set default URLs
		$isFromLoginPage = str_contains(url()->previous(), '/' . trans('routes.login'));
		$this->loginPath = $isFromLoginPage ? config('app.locale') . '/' . trans('routes.login') : url()->previous();
		$this->redirectTo = $isFromLoginPage ? config('app.locale') . '/account' : url()->previous();
    }
    
    /**
     * Redirect the user to the Provider authentication page.
     *
     * @return mixed
     */
    public function redirectToProvider()
    {
    	// Get the Provider and verify that if it's supported
        $provider = getSegment(2);
        if (!in_array($provider, $this->network)) {
            abort(404);
        }
	
		// If previous page is not the Login page...
		if (!str_contains(url()->previous(), trans('routes.login'))) {
			// Save the previous URL to retrieve it after success or failed login.
			session()->put('url.intended', url()->previous());
		}
        
        // Redirect to the Provider's website
        return Socialite::driver($provider)->redirect();
    }
    
    /**
     * Obtain the user information from Provider.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function handleProviderCallback()
    {
		// Get the Provider and verify that if it's supported
        $provider = getSegment(2);
        if (!in_array($provider, $this->network)) {
            abort(404);
        }
	
		// Check and retrieve previous URL to show the login error on it.
		if (session()->has('url.intended')) {
			$this->loginPath = session()->get('url.intended');
		}
        
        // Get the Country Code
        if (isset($this->country) && $this->country->has('code')) {
            $countryCode = $this->country->get('code');
        } else {
            $countryCode = (isset($this->ipCountry) && $this->ipCountry->has('code')) ? $this->ipCountry->get('code') : null;
        }
        
        // API CALL - GET USER FROM PROVIDER
        try {
            $userData = Socialite::driver($provider)->user();
            
            // Data not found
            if (!$userData) {
                $message = t("Unknown error. Please try again in a few minutes.");
                flash($message)->error();
                
                return redirect(config('app.locale') . '/' . trans('routes.login'));
            }
            
            // Email not found
            if (!$userData || !filter_var($userData->getEmail(), FILTER_VALIDATE_EMAIL)) {
                $message = t("Email address not found. You can't use your :provider account on our website.", ['provider' => ucfirst($provider)]);
                flash($message)->error();
                
                return redirect($this->loginPath);
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (is_string($message) && !empty($message)) {
                flash($message)->error();
            } else {
                $message = "Unknown error. The social network API doesn't work.";
                flash($message)->error();
            }
            
            return redirect($this->loginPath);
        }
        
        // Debug
        // dd($userData);
        
        // DATA MAPPING
        try {
            $mapUser = [];
            if ($provider == 'facebook') {
                $mapUser['name'] = (isset($userData->user['name'])) ? $userData->user['name'] : '';
                if ($mapUser['name'] == '') {
                    if (isset($userData->user['first_name']) && isset($userData->user['last_name'])) {
                        $mapUser['name'] = $userData->user['first_name'] . ' ' . $userData->user['last_name'];
                    }
                }
            } else {
                if ($provider == 'google') {
                    $mapUser = [
                        'name' => (isset($userData->name)) ? $userData->name : '',
                    ];
                }
            }
            
            // GET LOCAL USER
            $user = User::withoutGlobalScopes([VerifiedScope::class])->where('provider', $provider)->where('provider_id', $userData->getId())->first();
            
            // CREATE LOCAL USER IF DON'T EXISTS
            if (empty($user)) {
                // Before... Check if user has not signup with an email
                $user = User::withoutGlobalScopes([VerifiedScope::class])->where('email', $userData->getEmail())->first();
                if (empty($user)) {
                    $userInfo = [
                        'country_code'   => $countryCode,
                        'name'           => $mapUser['name'],
                        'email'          => $userData->getEmail(),
                        'ip_addr'        => Ip::get(),
                        'verified_email' => 1,
                        'verified_phone' => 1,
                        'provider'       => $provider,
                        'provider_id'    => $userData->getId(),
                        'created_at'     => date('Y-m-d H:i:s'),
                    ];
                    $user = new User($userInfo);
                    $user->save();
                    
                    
                    // Update Ads created by this email
                    if (isset($user->id) && $user->id > 0) {
                        Post::withoutGlobalScopes([VerifiedScope::class, ReviewedScope::class])->where('email', $userInfo['email'])->update(['user_id' => $user->id]);
                    }
                    
                    // Send Admin Notification Email
                    if (config('settings.mail.admin_email_notification') == 1) {
                        try {
                            // Get all admin users
                            $admins = User::where('is_admin', 1)->get();
                            if ($admins->count() > 0) {
                                foreach ($admins as $admin) {
                                    Mail::send(new UserNotification($user, $admin));
                                }
                            }
                        } catch (\Exception $e) {
                            flash($e->getMessage())->error();
                        }
                    }
                    
                } else {
                    // Update 'created_at' if empty (for time ago module)
                    if (empty($user->created_at)) {
						$user->created_at = date('Y-m-d H:i:s');
                    }
					$user->verified_email = 1;
					$user->verified_phone = 1;
					$user->save();
                }
            } else {
				// Update 'created_at' if empty (for time ago module)
				if (empty($user->created_at)) {
					$user->created_at = date('Y-m-d H:i:s');
				}
				$user->verified_email = 1;
				$user->verified_phone = 1;
				$user->save();
			}
            
            // GET A SESSION FOR USER
            if (Auth::loginUsingId($user->id)) {
                return redirect()->intended($this->redirectTo);
            } else {
                $message = t("Error on user's login.");
                flash($message)->error();
                
                return redirect($this->loginPath);
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (is_string($message) and !empty($message)) {
                flash($message)->error();
            } else {
                $message = "Unknown error. The service does not work.";
                flash($message)->error();
            }
            
            return redirect($this->loginPath);
        }
    }
}
