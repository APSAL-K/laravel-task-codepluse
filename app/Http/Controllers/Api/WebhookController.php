<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WebhookProcessor;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function razorpay(Request $request, WebhookProcessor $processor)
    {
        $result = $processor->handle(
            $request->getContent(),
            $request->header('X-Razorpay-Signature'),
            $request->header('X-Razorpay-Event-Id'),
        );

        return match ($result) {
            WebhookProcessor::INVALID_SIGNATURE, WebhookProcessor::MALFORMED => response()->json(['status' => $result], 400),
            WebhookProcessor::FAILED => response()->json(['status' => $result], 500),
            default => response()->json(['status' => $result], 200),
        };
    }
}
