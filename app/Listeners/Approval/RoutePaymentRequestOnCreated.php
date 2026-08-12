<?php

declare(strict_types=1);

namespace App\Listeners\Approval;

use App\Actions\Approval\RoutePaymentRequestForApprovalAction;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Exceptions\ApprovalException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class RoutePaymentRequestOnCreated
{
    public function __construct(
        private readonly RoutePaymentRequestForApprovalAction $routeAction,
    ) {}

    public function handle(PaymentRequestCreated $event): void
    {
        $paymentRequest = $event->paymentRequest;
        $actor = Auth::user();

        if (! $actor instanceof User) {
            $actor = User::query()->find($paymentRequest->created_by);
        }

        if (! $actor instanceof User) {
            Log::warning('Cannot route payment request for approval: no actor.', [
                'payment_request_id' => $paymentRequest->getKey(),
            ]);

            return;
        }

        try {
            ($this->routeAction)($paymentRequest, $actor);
        } catch (ApprovalException $exception) {
            if ($exception->getMessage() === ApprovalException::noMatchingRule()->getMessage()) {
                Log::warning('Payment request created without matching approval rule.', [
                    'payment_request_id' => $paymentRequest->getKey(),
                    'message' => $exception->getMessage(),
                ]);

                return;
            }

            throw $exception;
        }
    }
}
