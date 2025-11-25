<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Recall\RecallWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RecallWebhookController extends Controller
{
    public function webhook(Request $request): ApiResponse
    {
        Log::info('Recall webhook', $request->all());
        RecallWebhookService::handle($request->all());

        return ApiResponse::success();
    }
}
