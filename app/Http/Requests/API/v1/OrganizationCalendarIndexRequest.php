<?php

namespace App\Http\Requests\API\v1;

/**
 * Request for the organization calendar: the paginated, date-filtered list of a
 * single organization's bot meetings. Extends the shared calendar request with a
 * required organization_id so the shared one stays usable by endpoints that are
 * not scoped to one organization (e.g. the viewable meetings picker).
 */
class OrganizationCalendarIndexRequest extends OrganizationCalendarRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);
    }

    public function getOrganizationId(): int
    {
        return (int) $this->input('organization_id');
    }
}
