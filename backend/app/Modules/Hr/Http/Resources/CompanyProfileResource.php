<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'address' => $this->address,
            'tin' => $this->tin,
            'sss_employer_number' => $this->sss_employer_number,
            'philhealth_employer_number' => $this->philhealth_employer_number,
            'pagibig_employer_number' => $this->pagibig_employer_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            // Short-lived signed URL — the logo sits on the private disk, like employee photos.
            'logo_url' => PrivateFileService::url($this->logo_path),
        ];
    }
}
