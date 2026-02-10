<?php

namespace App\Services\Chat;

class FollowupHtmlRenderer
{
    public function renderError(string $message): string
    {
        return view('followup.error', [
            'message' => $message,
        ])->render();
    }

    public function renderNotFound(): string
    {
        return $this->renderError('Данные не найдены');
    }
}
