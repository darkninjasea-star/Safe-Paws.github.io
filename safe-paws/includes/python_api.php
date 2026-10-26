<?php
declare(strict_types=1);

/**
 * Call the Python API to submit an adoption request.
 * Returns an array with success flag, message, and optional data.
 */
function call_python_api_adoption(array $payload): array
{
    $url = python_api_base_url() . '/api/v1/adoption-request';
    return call_python_api($url, $payload);
}

/**
 * Call the Python API to submit a stray report.
 */
function call_python_api_stray_report(array $payload): array
{
    $url = python_api_base_url() . '/api/v1/stray-report';
    return call_python_api($url, $payload);
}

function python_api_base_url(): string
{
    return rtrim(getenv('PYTHON_API_BASE_URL') ?: 'http://127.0.0.1:5000', '/');
}

/**
 * Generic helper for calling the Python API.
 */
function call_python_api(string $url, array $payload): array
{
    if (!function_exists('curl_init')) {
        error_log('Safe PAWS requires the PHP cURL extension for Python API requests.');
        return [
            'success' => false,
            'message' => 'The rescue coordination service is unavailable. Please enable PHP cURL and try again.',
            'http_code' => 0,
        ];
    }
    $token = getenv('SAFE_PAWS_API_TOKEN') ?: '';
    if ($token === '') {
        error_log('Safe PAWS Python API token is not configured.');
        return [
            'success' => false,
            'message' => 'The rescue coordination service is not configured. Please contact support.',
            'http_code' => 0,
        ];
    }

    try {
        $requestBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Safe PAWS could not encode a Python API request: ' . $exception->getMessage());
        return [
            'success' => false,
            'message' => 'The request contains data that could not be processed.',
            'http_code' => 0,
        ];
    }

    $ch = curl_init();
    if ($ch === false) {
        error_log('Safe PAWS could not initialize PHP cURL.');
        return [
            'success' => false,
            'message' => 'The rescue coordination service is unavailable. Please try again later.',
            'http_code' => 0,
        ];
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: ' . $token,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log('Python API connection error: ' . $error);
        return [
            'success' => false,
            'message' => 'Could not reach the rescue coordination service. Please try again later.',
            'http_code' => 0,
        ];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        error_log('Python API invalid response: ' . substr((string) $response, 0, 200));
        return [
            'success' => false,
            'message' => 'The rescue coordination service returned an unexpected response.',
            'http_code' => $httpCode,
        ];
    }

    if ($httpCode >= 200 && $httpCode < 300 && ($data['success'] ?? false)) {
        return [
            'success' => true,
            'message' => $data['message'] ?? 'Request processed successfully.',
            'data' => $data,
            'http_code' => $httpCode,
        ];
    }

    error_log('Python API error: ' . json_encode(['code' => $httpCode, 'data' => $data]));
    return [
        'success' => false,
        'message' => in_array($httpCode, [400, 404, 409, 422], true)
            ? ($data['error'] ?? 'The submitted information could not be processed.')
            : 'The rescue coordination service could not save your request. Please try again later.',
        'http_code' => $httpCode,
    ];
}