<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A discovered business, shaped for the Nearby Clients UI. This is the live replacement for the
 * frontend's former demo dataset — the same shape, now sourced from real discovered leads.
 *
 * Deliberately **provider-agnostic**: it never exposes which discovery provider (`source`) supplied
 * the record — the CRM/UI must not depend on the provider. Coordinates are included (stored this
 * milestone); the distance/marker layout that consumes them is the next milestone, so this resource
 * emits no fabricated distance or map position.
 *
 * @mixin Lead
 */
class NearbyClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'business' => $this->business_name,
            'initials' => $this->initialsFor($this->business_name),
            'niche' => $this->industry,
            // Human area label — most specific administrative unit available.
            'area' => $this->city ?? $this->province ?? $this->region,
            'contact' => $this->contact_name,
            'status' => $this->status,
            'priority' => $this->priority,
            // A discovered business is "active" unless it has been declined.
            'active' => $this->status !== 'declined',
            // Real coordinates (WGS84) captured at discovery — the map that renders them is the
            // next milestone. No distance/marker position is computed or fabricated here.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    private function initialsFor(string $businessName): string
    {
        $words = preg_split('/\s+/', trim($businessName)) ?: [];
        $letters = '';
        foreach ($words as $word) {
            $first = Str::substr($word, 0, 1);
            if ($first !== '') {
                $letters .= Str::upper($first);
            }
            if (Str::length($letters) >= 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : '?';
    }
}
