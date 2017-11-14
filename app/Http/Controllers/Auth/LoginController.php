<?php

namespace App\Http\Controllers\Auth;

use Utils;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Event;
use Cache;
use App\Events\UserLoggedIn;
use App\Http\Requests\ValidateTwoFactorRequest;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest', ['except' => 'getLogoutWrapper']);
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function getLoginWrapper(Request $request)
    {
        if (auth()->check()) {
            return redirect('/');
        }

        if (! Utils::isNinja() && ! User::count()) {
            return redirect()->to('/setup');
        }

        if (Utils::isNinja() && ! Utils::isTravis()) {
            // make sure the user is on SITE_URL/login to ensure OAuth works
            $requestURL = request()->url();
            $loginURL = SITE_URL . '/login';
            $subdomain = Utils::getSubdomain(request()->url());
            if ($requestURL != $loginURL && ! strstr($subdomain, 'webapp-')) {
                return redirect()->to($loginURL);
            }
        }

        return self::showLoginForm($request);
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function postLoginWrapper(Request $request)
    {
        $userId = auth()->check() ? auth()->user()->id : null;
        $user = User::where('email', '=', $request->input('email'))->first();

        if ($user && $user->failed_logins >= MAX_FAILED_LOGINS) {
            session()->flash('error', trans('texts.invalid_credentials'));
            return redirect()->to('login');
        }

        $response = self::login($request);

        if (auth()->check()) {
            /*
            $users = false;
            // we're linking a new account
            if ($request->link_accounts && $userId && Auth::user()->id != $userId) {
                $users = $this->accountRepo->associateAccounts($userId, Auth::user()->id);
                Session::flash('message', trans('texts.associated_accounts'));
                // check if other accounts are linked
            } else {
                $users = $this->accountRepo->loadAccounts(Auth::user()->id);
            }
            */
        } elseif ($user) {
            error_log('login failed');
            $user->failed_logins = $user->failed_logins + 1;
            $user->save();
        }

        return $response;
    }

    /**
     * Send the post-authentication response.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Illuminate\Contracts\Auth\Authenticatable $user
     * @return \Illuminate\Http\Response
     */
    private function authenticated(Request $request, Authenticatable $user)
    {
        if ($user->google_2fa_secret) {
            auth()->logout();
            session()->put('2fa:user:id', $user->id);
            return redirect('/validate_two_factor/' . $user->account->account_key);
        }

        Event::fire(new UserLoggedIn());

        return redirect()->intended($this->redirectTo);
    }

    /**
     *
     * @return \Illuminate\Http\Response
     */
    public function getValidateToken()
    {
        if (session('2fa:user:id')) {
            return view('auth.two_factor');
        }

        return redirect('login');
    }

    /**
     *
     * @param  App\Http\Requests\ValidateSecretRequest $request
     * @return \Illuminate\Http\Response
     */
    public function postValidateToken(ValidateTwoFactorRequest $request)
    {
        //get user id and create cache key
        $userId = session()->pull('2fa:user:id');
        $key = $userId . ':' . $request->totp;

        //use cache to store token to blacklist
        Cache::add($key, true, 4);

        //login and redirect user
        auth()->loginUsingId($userId);
        Event::fire(new UserLoggedIn());

        return redirect()->intended($this->redirectTo);
    }

    /**
     * @return \Illuminate\Http\Response
     */
    public function getLogoutWrapper(Request $request)
    {
        if (auth()->check() && ! auth()->user()->registered) {
            if (request()->force_logout) {
                $account = auth()->user()->account;
                $this->accountRepo->unlinkAccount($account);

                if (! $account->hasMultipleAccounts()) {
                    $account->company->forceDelete();
                }
                $account->forceDelete();
            } else {
                return redirect('/');
            }
        }

        $response = self::logout($request);

        $reason = htmlentities(request()->reason);
        if (!empty($reason) && Lang::has("texts.{$reason}_logout")) {
            sesion()->flash('warning', trans("texts.{$reason}_logout"));
        }

        return $response;
    }
}
