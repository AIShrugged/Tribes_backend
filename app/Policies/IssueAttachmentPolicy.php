<?php

namespace App\Policies;

use App\Models\IssueAttachment;
use App\Models\User;

class IssueAttachmentPolicy
{
    public function deletePending(User $user, IssueAttachment $attachment): bool
    {
        return $attachment->issue_id === null
            && $attachment->uploaded_by_user_id === $user->id;
    }
}