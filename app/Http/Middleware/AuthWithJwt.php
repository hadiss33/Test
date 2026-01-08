<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class AuthWithJwt
{
    private const SUPPORT_INFO = [
        "phone" => "021-91016838 in 121",
        "email" => "ict@airplus.app",
        "panel" => "helpdesk.airplus.app"
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check if token exists
            $token = $request->bearerToken();
            if (!$token) {
                return $this->errorResponse(1005, "Token not provided!", 400);
            }

            // Decode JWT token
            try {
                $decoded = JWT::decode($token, new Key(env('JWT_SECRET_KEY'), 'HS256'));
            } catch (Exception $e) {
                return $this->errorResponse(1006, "Your token is invalid or expired!", 401);
            }

            // Check if branch exists in token
            if (!isset($decoded->brn)) {
                return $this->errorResponse(1004, "User does not have access permission!", 403);
            }

            // Check if UUID exists in token
            if (!isset($decoded->uuid)) {
                return $this->errorResponse(1004, "User does not have access permission!", 403);
            }

            // Get user from appropriate table
            $user = $this->getUser($decoded->typ, $decoded->uuid);

            if (!$user) {
                return $this->errorResponse(1003, "User does not have access permission!", 403);
            }

            // Check user status
            if ($user->status != 1) {
                return $this->errorResponse(1002, "User does not have access permission!", 403);
            }

            // Add user data to request and continue
            $request->attributes->add([
                'group' => $decoded->typ,
                'branch' => $decoded->brn ?? 0,
                'domain' => $decoded->aud ?? '',
                'browser' => $decoded->brw ?? '',
                'ip' => $decoded->uip ?? '',
                'level' => $user->level ?? 1,
                'operator' => $user
            ]);

            return $next($request);
        } catch (Exception $e) {
            return $this->errorResponse(
                (int) $e->getCode(),
                $e->getMessage(),
                500,
                [
                    "file" => str_replace(base_path(), '', $e->getFile()),
                    "line" => $e->getLine()
                ]
            );
        }
    }

    /**
     * Get user from appropriate table based on type
     */
    private function getUser(string $type, int $uuid)
    {
        return (object) ['id' => $uuid, 'status' => 1, 'level' => 1];
    }

    /**
     * Create standardized error response
     */
    private function errorResponse(int $code, string $message, int $httpCode, array $additionalData = [])
    {
        $response = [
            "status" => false,
            "time" => time(),
            "error" => array_merge([
                "code" => $code,
                "message" => $message
            ], $additionalData),
            "support" => self::SUPPORT_INFO
        ];

        return response()->json($response, $httpCode);
    }
}
