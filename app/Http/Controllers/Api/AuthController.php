<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Jobs\SendWelcomeEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        // Sent in the background so the response is not slowed down by the mail server.
        SendWelcomeEmail::dispatch($user);

        return response()->json([
            'user' => $this->userPayload($request, $user),
            'token' => $user->createToken('api')->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Same message for "no such email" and "wrong password", so emails can't be probed.
        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        return response()->json([
            'user' => $this->userPayload($request, $user),
            'token' => $user->createToken($request->validated('device_name', 'api'))->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * The new token isn't on the request yet, so tell the resource who "me" is
     * (this makes the user's own email visible in the response).
     */
    private function userPayload(Request $request, User $user): array
    {
        $request->setUserResolver(fn () => $user);

        return (new UserResource($user))->resolve($request);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the token used for this request; other devices stay logged in.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
