<?php

namespace Webkul\SocialLogin\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Facades\Socialite;
use Webkul\SocialLogin\Repositories\CustomerSocialAccountRepository;

class LoginController extends Controller
{
    use DispatchesJobs, ValidatesRequests;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected CustomerSocialAccountRepository $customerSocialAccountRepository) {}

    /**
     * Redirects to the social provider
     *
     * @param  string  $provider
     * @return \Illuminate\Http\Response
     */
    public function redirectToProvider($provider)
    {
        try {
            // 前台已停用：原 redirect('shop.customers.account.profile.index')
            return Socialite::driver($provider)->redirect('/admin');
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());

            return redirect('/admin'); // 原 redirect()->route('shop.customer.session.index')
        }
    }

    /**
     * Handles callback
     *
     * @param  string  $provider
     * @return \Illuminate\Http\Response
     */
    public function handleProviderCallback($provider)
    {
        try {
            $user = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect('/admin'); // 原 redirect()->route('shop.customer.session.index')
        }

        $customer = $this->customerSocialAccountRepository->findOrCreateCustomer($user, $provider);

        auth()->guard('customer')->login($customer, true);

        Event::dispatch('customer.after.login', $customer);

        // 前台已停用：原 redirect()->intended(route('shop.customers.account.profile.index'))
        return redirect()->intended('/admin');
    }
}
