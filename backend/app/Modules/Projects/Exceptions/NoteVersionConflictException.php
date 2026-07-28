<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use App\Modules\Projects\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The client saved against a version the server has already moved past — the
 * same person editing from another tab or device.
 *
 * Renders itself so the controller stays linear: the response carries the
 * server's current version and document so the client can offer
 * "Load latest" / "Keep mine" without a second round-trip.
 */
class NoteVersionConflictException extends RuntimeException
{
    public function __construct(private readonly Note $note)
    {
        parent::__construct('This note changed on another device.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'version_conflict',
            'data' => [
                'version' => $this->note->version,
                'content' => $this->note->content,
                'last_saved_at' => $this->note->last_saved_at?->toIso8601String(),
            ],
        ], 409);
    }
}
