<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class ApiResponse implements Responsable
{
    protected bool $success;
    protected mixed $data;
    protected string $message;
    protected int $status;
    protected array $meta;
    protected array $headers;

    public function __construct(
        bool $success = true,
        string $message = null,
        mixed $data = null,
        int $status = 200,
        array $meta = [],
        array $headers = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
        $this->status = $status;
        $this->meta = $meta;
        $this->headers = $headers;
    }

    public static function success(
        string $message = 'Success',
        mixed $data = null,
        int $status = 200,
        array $meta = [],
        array $headers = []
    ): ApiResponse {
        return new self(true, $message, $data, $status, $meta, $headers);
    }

    public static function error(
        string $message = 'Error',
        mixed $data = null,
        int $status = 400,
        array $meta = [],
        array $headers = []
    ): ApiResponse {
        return new self(false, $message, $data, $status, $meta, $headers);
    }

    public static function list(
        mixed $data = [],
        int $count = 0
    ): ApiResponse
    {
        return new self(
            true,
            'Success',
            $data,
            headers: ['Access-Control-Expose-Headers' => 'Items-Count', ['Items-Count' => $count]]
        );
    }

    public static function notFound(): ApiResponse
    {
        return new self(false, 'Not Found', status: 404);
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'success' => $this->success,
            'data'    => $this->data,
            'message' => $this->message,
            'status'  => $this->status,
            'meta'    => $this->meta,
        ], $this->status, $this->headers);
    }
}
