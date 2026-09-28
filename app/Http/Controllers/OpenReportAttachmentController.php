<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Report\OpenReportAttachmentAction;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OpenReportAttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment, OpenReportAttachmentAction $action): RedirectResponse|StreamedResponse
    {
        return $action($attachment, $request->user(), $request->ip());
    }
}
