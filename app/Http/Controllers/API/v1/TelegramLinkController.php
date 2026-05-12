<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\TelegramLinkService;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Authenticated;
use Knuckles\Scribe\Attributes\Group;

/**
 * @group Telegram
 */
#[Group('Telegram')]
class TelegramLinkController extends Controller
{
    public function __construct(
        private readonly TelegramLinkService $telegramLink,
    ) {}

    /**
     * Generate Telegram link token
     *
     * Generates a one-time deep link for connecting the authenticated user's
     * Telegram account. The link is valid for 10 minutes. Any previously
     * generated unused tokens are invalidated.
     *
     * @authenticated
     *
     * @response 200 scenario="OK" {
     *   "success": true,
     *   "data": {
     *     "link_url": "https://t.me/spodial_bot?start=abc123def456abc123def456abc123de",
     *     "expires_at": "2026-05-08T12:10:00.000000Z"
     *   },
     *   "message": "Success",
     *   "status": 200,
     *   "meta": {}
     * }
     */
    public function generate(Request $request): ApiResponse
    {
        $user = $request->user();
        $linkToken = $this->telegramLink->generateLink($user);

        return ApiResponse::success(data: [
            'link_url' => $this->telegramLink->getLinkUrl($linkToken),
            'expires_at' => $linkToken->expires_at,
        ]);
    }
}
