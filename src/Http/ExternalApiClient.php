<?php

namespace App\DiscordBot\Http;

class ExternalApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $token,
    ) {}

    public function post(string $path, array $data): string
    {
        return $this->request("POST", $path, $data);
    }

    public function patch(string $path, array $data): string
    {
        return $this->request("PATCH", $path, $data);
    }

    private function request(string $method, string $path, array $data): string
    {
        $endpoint = $this->baseUrl . $path;
        $payload = json_encode($data);

        $ch = curl_init($endpoint);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-type: application/json",
            "Content-Length: " . strlen($payload),
            "Authorization: Bearer " . $this->token,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Erro ao enviar {$method}: " . $error);
        }

        curl_close($ch);

        return $response;
    }
}
