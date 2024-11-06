<?php
namespace App\Http\Controllers\API;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\User;
use App\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Validator;
use App\Notifications\SignupActivate;
use App\Notifications\PasswordResetRequest;
use App\Notifications\PasswordResetSuccess;
use Carbon\Carbon;


class UserController extends Controller
{

     /**
     * Operation doLogin
     *
     * @param [string] email
     * @param [string] password
     * @return \Illuminate\Http\Response
     */

    public function doLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:40',
            'password' => 'required|between:8,40',
        ]);
        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }

        $credentials = request(['email', 'password']);
        $credentials['active'] = 1;
        $credentials['deleted_at'] = null;

        if(Auth::attempt($credentials))
        {
            $user = Auth::user();
            $responseObj = $user;
            $responseObj['apiKey'] =  $user->createToken('picture-api')-> accessToken;
            return response()->json($responseObj, 200);
        }
        else {
            return response()->json(['error'=>'Unauthorized request'], 401);
        }
    }

     /**
     * Operation logout
     *
     * @return \Illuminate\Http\Response
     */

     public function logout()
     {
         $accessToken = Auth::user()->token();
         DB::table('oauth_refresh_tokens')
             ->where('access_token_id', $accessToken->id)
             ->update([
                 'revoked' => true
             ]);

         $accessToken->revoke();
         return response()->json(['OK'=> 'signed out'], 200);
     }

     /**
     * Operation doRegister
     *
     * @param [string] institute
     * @param [string] name
     * @param [string] email
     * @param [string] password
     * @param [string] vPassword
     * @return \Illuminate\Http\Response
     */
    public function doRegister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'institute' => 'required|max:30',
            'name' => 'required|max:30',
            'email' => 'required|email|unique:users,email|max:40', // email_domain removed
            'password' => 'required|between:8,40',
            'vPassword' => 'required|between:8,40|same:password',
        ]);
        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }
        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $input['activation_token'] = str_random(60);
        $user = User::create($input);
        $user->notify(new SignupActivate($user));
        $responseObj = $user;
        return response()->json($responseObj, 200);
    }

    /**
     * Operation resendActivationMail
     *
     * @param [string] email
     * @return \Illuminate\Http\Response
     */
    public function resendActivationMail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user)
        {
            return response()->json(['error'=>'Unknown email address'], 400);
        }

        if($user->active == True)
        {
            return response()->json(['error'=>'Account already activated'], 400);
        }

        $user->notify(new SignupActivate($user));
        return response()->json(['status'=>'OK'], 200);

    }

    public function registerActivate($token)
    {
        $user = User::where('activation_token', $token)->first();
        if (!$user) {
            return response()->json([
                'message' => 'This activation token is invalid.'
            ], 401);
        }
        $user->active = true;
        $user->activation_token = '';
        $user->save();
        return $user;
    }

     /**
     * Operation getUserProfile
     *
     * @return \Illuminate\Http\Response
     */
    public function getUserProfile()
    {
        $user = Auth::user();
        $responseObj = $user;
        return response()->json($responseObj, 200);
    }

     /**
     * Operation updateUserProfile
     *
     * @param [string] institute
     * @param [string] name
     * @param [string] email
     * @return \Illuminate\Http\Response
     */
    public function updateUserProfile(Request $request)
    {
        $user = Auth::user();

        if ($request->has('email'))
        {
            if ($request->email == $user->email) {
                unset($request['email']);
            }
        }

        $validator = Validator::make($request->all(), [
            'institute' => 'sometimes|required|max:30',
            'name' => 'sometimes|required|max:30',
            'email' => 'sometimes|required|email|unique:users,email|max:40',
        ]);
        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }

        if ($request->has('institute'))
        {
            $user->institute = $request->institute;
        }
        if ($request->has('name'))
        {
            $user->name = $request->name;
        }
        if ($request->has('email'))
        {
            $user->email = $request->email;
        }
        $user->save();
        $responseObj = $user;
        return response()->json($responseObj, 200);
    }

     /**
     * Operation changePassword
     *
     * @param [string] oldPassword
     * @param [string] newPassword
     * @param [string] vPassword
     * @return \Illuminate\Http\Response
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'oldPassword' => 'required|max:40',
            'newPassword' => 'required|between:8,40|distinct:oldPassword',
            'vPassword' => 'required|between:8,40|same:newPassword',
        ]);

        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }

        $user = Auth::user();

        if(Hash::check($request->oldPassword, $user->password))
        {
            $user->password = bcrypt($request->newPassword);
            $user->save();
        }
        else {
            $error = 'Please enter correct current password';
            return response()->json(['error' => $error], 400);
        }

        $accessToken = $user->token();
        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $accessToken->id)
            ->update([
                'revoked' => true
            ]);

        $accessToken->revoke();

        $responseObj = $user;
        $responseObj['apiKey'] = $user->createToken('picture-api')-> accessToken;
        return response()->json($responseObj, 200);
    }

    /**
     * Operation requestPasswordReset
     *
     * @param [string] email
     * @return \Illuminate\Http\Response
     */
    public function requestPasswordReset(Request $request) {

        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:40'
        ]);

        if ($validator->fails())
        {
            return response()->json(['error'=>$validator->errors()], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user)
        {
            return response()->json(['error' => 'invalid email address'], 400);
        }

        $passwordReset = PasswordReset::updateOrCreate(
            ['email' => $user->email],
            ['email' => $user->email, 'token' => str_random(60)]
        );

        $passwordReset->save();

        if ($user && $passwordReset)
        {
            $user->notify(new PasswordResetRequest($passwordReset->token));
        }

        return response()->json(['OK' => 'password reset notification mail created'], 200);
    }

    /**
     * Operation findPasswordResetRequest
     *
     * @param  [string] $token
     * @return \Illuminate\Http\Response
     */
    public function findPasswordResetRequest($token)
    {
        $passwordReset = PasswordReset::where('token', $token)
            ->first();
        if (!$passwordReset)
            return response()->json([
                'error' => 'this password reset token is invalid.'
            ], 401);
        if (Carbon::parse($passwordReset->updated_at)->addMinutes(720)->isPast()) {
            $passwordReset->delete();
            return response()->json([
                'error' => 'this password reset token is expired.'
            ], 400);
        }
        return response()->json($passwordReset, 200);
    }

    /**
     * Operation resetPassword
     *
     * @param  [string] email
     * @param  [string] newPassword
     * @param  [string] vPassword
     * @param  [string] token
     * @return \Illuminate\Http\Response
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'newPassword' => 'required|between:8,40',
            'vPassword' => 'required|between:8,40|same:newPassword',
            'token' => 'required|string'
        ]);
        $passwordReset = PasswordReset::where([
            ['token', $request->token],
            ['email', $request->email]
        ])->first();

        if (!$passwordReset)
            return response()->json([
                'error' => 'this password reset token is invalid.'
            ], 401);
        $user = User::where('email', $passwordReset->email)->first();
        if (!$user)
            return response()->json([
                'error' => 'unknown email address'
            ], 401);
        $user->password = bcrypt($request->newPassword);
        $user->save();

        $credentials = [
            'email' => $request->email,
            'password' => $request->newPassword
        ];

        if(Auth::attempt($credentials))
        {
            DB::table('oauth_access_tokens')
                ->where('user_id', $user->id)
                ->update([
                    'revoked' => true
                ]);
        }

        $passwordReset->delete();
        $user->notify(new PasswordResetSuccess($passwordReset));

        $responseObj = $user;
        $responseObj['apiKey'] = $user->createToken('picture-api')-> accessToken;
        return response()->json($responseObj, 200);
    }

    /**
     * Operation deleteUserProfile
     *
     * @return \Illuminate\Http\Response
     */

     public function deleteUserProfile()
     {
         $user = User::find(Auth::user()->id);
         $accessToken = Auth::user()->token();
         DB::table('oauth_refresh_tokens')
             ->where('access_token_id', $accessToken->id)
             ->update([
                 'revoked' => true
             ]);

         $accessToken->revoke();
         $user->forceDelete();
         return response()->json(['OK'=> 'profile deleted'], 200);
     }
}
