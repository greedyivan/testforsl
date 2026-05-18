<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GetSubscriberNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Repositories\NotificationRepository;
use Illuminate\Http\JsonResponse;

class SubscriberController extends Controller
{
    public function __construct(private readonly NotificationRepository $repository) {}

    /**
     * Список уведомлений подписчика с опциональными фильтрами и пагинацией.
     *
     * @OA\Get(
     *   path="/api/v1/subscribers/{subscriberId}/notifications",
     *   summary="Get subscriber notification history",
     *   @OA\Parameter(name="subscriberId", in="path", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="status",  in="query", required=false, @OA\Schema(type="string", enum={"queued","sent","delivered","dropped"})),
     *   @OA\Parameter(name="channel", in="query", required=false, @OA\Schema(type="string", enum={"sms","email"})),
     *   @OA\Parameter(name="limit",   in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=200, default=50)),
     *   @OA\Parameter(name="offset",  in="query", required=false, @OA\Schema(type="integer", minimum=0, default=0)),
     *   @OA\Response(response=200, description="Notification list"),
     *   @OA\Response(response=422, description="Неверное значение фильтра или пагинационного параметра")
     * )
     */
    public function index(GetSubscriberNotificationsRequest $request, string $subscriberId): JsonResponse
    {
        ['total' => $total, 'items' => $notifications] = $this->repository->listForSubscriber(
            recipientId: $subscriberId,
            status:      $request->input('status'),
            channel:     $request->input('channel'),
            limit:       $request->limit(),
            offset:      $request->offset(),
        );

        return response()->json([
            'subscriber_id' => $subscriberId,
            'total'         => $total,
            'notifications' => NotificationResource::collection($notifications),
        ]);
    }
}
