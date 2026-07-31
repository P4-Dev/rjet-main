<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequest;

use App\DTOs\BoletoOcrResult;
use App\Integrations\Ocr\BoletoOcrClient;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

final class ExtractBoletoDataAction
{
    public function __construct(
        private readonly BoletoOcrClient $client,
    ) {}

    public function fromUploadedFile(TemporaryUploadedFile|UploadedFile $file): BoletoOcrResult
    {
        if (! config('rjet.ocr.enabled')) {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'));
        }

        $mimeType = (string) ($file->getMimeType() ?? '');

        if ($mimeType !== 'application/pdf') {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_image_not_supported'));
        }

        try {
            $absolutePath = $file->getRealPath();

            if ($absolutePath === false || $absolutePath === '') {
                return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
            }

            return $this->client->extract($absolutePath, $mimeType);
        } catch (Throwable $exception) {
            Log::warning('Boleto OCR failed for uploaded file.', [
                'exception' => $exception->getMessage(),
            ]);

            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
        }
    }

    public function fromAttachment(Attachment $attachment): BoletoOcrResult
    {
        if (! config('rjet.ocr.enabled')) {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_disabled'));
        }

        if ($attachment->mime_type !== 'application/pdf') {
            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_image_not_supported'));
        }

        $tmpPath = null;

        try {
            $disk = Storage::disk($attachment->disk);

            if (method_exists($disk, 'path') && config("filesystems.disks.{$attachment->disk}.driver") === 'local') {
                $absolutePath = $disk->path($attachment->path);
            } else {
                $tmpPath = tempnam(sys_get_temp_dir(), 'boleto_ocr_');

                if ($tmpPath === false) {
                    return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
                }

                $stream = $disk->readStream($attachment->path);

                if ($stream === false) {
                    return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
                }

                file_put_contents($tmpPath, stream_get_contents($stream) ?: '');
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $absolutePath = $tmpPath;
            }

            return $this->client->extract($absolutePath, $attachment->mime_type);
        } catch (Throwable $exception) {
            Log::warning('Boleto OCR failed for attachment.', [
                'attachment_id' => $attachment->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return BoletoOcrResult::failed(__('payment_requests.messages.ocr_failed'));
        } finally {
            if ($tmpPath !== null && file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }
}
