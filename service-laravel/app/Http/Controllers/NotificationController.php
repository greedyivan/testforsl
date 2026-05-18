<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BulkSendRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    /**
     * Принять bulk-запрос и поставить задачи в очередь для каждого получателя.
     *
     * @OA\Post(
     *   path="/api/v1/notifications/bulk",
     *   summary="Send bulk notifications",
     *   @OA\RequestBody(required=true,
     *     @OA\JsonContent(
     *       required={"channel","type","recipient_ids","message","idempotency_key"},
     *       @OA\Property(property="channel", type="string", enum={"sms","email"}),
     *       @OA\Property(property="type", type="string", enum={"transactional","marketing"}),
     *       @OA\Property(property="recipient_ids", type="array", @OA\Items(type="string")),
     *       @OA\Property(property="message", type="string"),
     *       @OA\Property(property="idempotency_key", type="string"),
     *     )
     *   ),
     *   @OA\Response(response=202, description="Accepted"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function bulkSend(BulkSendRequest $request): JsonResponse
    {
        $response = $this->service->bulkSend(
            idempotencyKey: $request->string('idempotency_key')->toString(),
            recipientIds:   $request->input('recipient_ids'),
            channel:        $request->channel(),
            type:           $request->notificationType(),
            message:        $request->string('message')->toString(),
        );

        return response()->json($response, 202);
    }
}
