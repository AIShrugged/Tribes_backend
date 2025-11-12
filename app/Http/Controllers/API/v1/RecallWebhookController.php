<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;

class RecallWebhookController extends Controller
{
    public function webhook(Request $request): ApiResponse
    {
        //TODO: получаем внешний id события и резолвим класс-хендлер по типу источника

        return ApiResponse::success();
    }
}
