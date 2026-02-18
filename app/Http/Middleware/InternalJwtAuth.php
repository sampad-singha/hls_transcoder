<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalJwtAuth
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Missing Token'], 401);
        }

        try {
            // Use the same secret defined in both apps
            $secret = config('services.internal_jwt_secret');

            // Decode and verify the signature
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Verification of the issuer (Main App)
            if ($decoded->iss !== 'main-app') {
                return response()->json(['message' => 'Invalid Issuer'], 401);
            }

        } catch (Exception $e) {
            return response()->json(['message' => 'Unauthorized: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
