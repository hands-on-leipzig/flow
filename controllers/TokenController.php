<?php
namespace controllers;

use Dotenv\Dotenv;

use Firebase\JWT\JWT;

class TokenController
{
    public function getJWTToken($code)
    {
        $dotenv = Dotenv::createImmutable(__DIR__, "../conf.env");
        $dotenv->load();

        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json");

        //$data = json_decode(file_get_contents("php://input"), true);
        //$code = $data['code'];

        $fields = [
            "grant_type" => "authorization_code",
            "client_id" => $_ENV["CLIENT_ID"],
            "client_secret" => $_ENV["CLIENT_SECRET"],
            "code" => $code,
            "redirect_uri" => "http://localhost:5173/callback"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $_ENV["KEYCLOAK_URL"]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $keycloak_response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!isset($keycloak_response["access_token"])) {
            http_response_code(401);
            echo json_encode(["error" => "Invalid authentication"]);
            exit;
        }

        /*$jwt_payload = [
            "sub" => $keycloak_response["sub"],
            "username" => $keycloak_response["preferred_username"],
            "exp" => time() + 3600 // Token expires in 1 hour
        ];*/
        $jwt_payload = [
            "iss" => "your-issuer",        // Issuer (who issued the token)
            "aud" => "your-audience",      // Audience (who the token is intended for)
            "iat" => time(),               // Issued At (current time)
            "exp" => time() + 3600,        // Expiration Time (1 hour from now)
            "data" => [                    // Custom Payload Data
                "user_id" => 123,
                "username" => "john_doe"
            ]
        ];

        return json_encode(JWT::encode($jwt_payload, $_ENV["JWT_SECRET"], 'HS256'));
    }
}