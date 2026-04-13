<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Paperclip\PaperclipWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaperclipWebhookController extends Controller
{
    /**
     * @hideFromAPIDocumentation
     */
    public function webhook(Request $request): ApiResponse
    {
        Log::info('Paperclip webhook', $request->all());
        PaperclipWebhookService::handle($request->all());

        return ApiResponse::success();
    }
}
