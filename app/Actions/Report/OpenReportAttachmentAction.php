<?php

declare(strict_types=1);

namespace App\Actions\Report;

use App\Models\Attachment;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OpenReportAttachmentAction
{
    public function __invoke(Attachment $attachment, User $actor, ?string $ipAddress = null): RedirectResponse|StreamedResponse
    {
        abort_unless($attachment->attachable_type === (new PaymentRequest)->getMorphClass(), 404);

        Gate::forUser($actor)->authorize('view', $attachment);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        Log::info('Report attachment opened.', [
            'attachment_id' => $attachment->getKey(),
            'payment_request_id' => $attachment->attachable_id,
            'user_id' => $actor->getKey(),
            'ip' => $ipAddress,
        ]);

        $url = $attachment->temporaryUrl((int) config('rjet.reports.attachment_redirect_ttl_minutes'));

        if ($url !== null) {
            return redirect()->away($url);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->displayName());
    }
}
