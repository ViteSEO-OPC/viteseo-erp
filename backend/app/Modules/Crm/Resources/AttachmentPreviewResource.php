<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Services\Attachments\SpreadsheetPreview;
use App\Modules\Crm\Services\Attachments\SpreadsheetPreviewReader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bounded spreadsheet window, in the platform envelope.
 *
 * `rows` is a plain array of arrays of STRINGS — never generated HTML. The client renders each value
 * as a React text node, so a cell containing `<script>` or a `=cmd|...` payload is displayed, not
 * interpreted, and `dangerouslySetInnerHTML` is never needed anywhere in the preview path.
 *
 * The window limits are echoed rather than duplicated in the frontend, so the viewer's "showing the
 * first N rows" line can never drift from what the reader actually returned.
 */
class AttachmentPreviewResource extends JsonResource
{
    /** @var SpreadsheetPreview */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'sheet',
            'sheetName' => $this->resource->sheetName,
            'sheetCount' => $this->resource->sheetCount,
            'rows' => $this->resource->rows,
            // The sheet's REAL size, so the viewer can say honestly what it is not showing.
            'totalRows' => $this->resource->totalRows,
            'totalColumns' => $this->resource->totalColumns,
            'truncated' => $this->resource->isTruncated(),
            'maxRows' => SpreadsheetPreviewReader::MAX_ROWS,
            'maxColumns' => SpreadsheetPreviewReader::MAX_COLUMNS,
        ];
    }
}
