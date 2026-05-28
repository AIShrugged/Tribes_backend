<?php

namespace App\Http\Resources\API\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssueAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'file_path'    => $this->file_path,
            'original_name' => $this->original_name ?: ($this->file_path ? basename($this->file_path) : null),
            'file_url'     => route('attachments.download.auth', ['attachment' => $this->id]),
            'issue_id'     => $this->issue_id,
            'upload_token' => $this->upload_token,
            'uploaded_at'  => $this->uploaded_at,
        ];
    }
}
