<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * @OA\Info(
 *   title="Notification Service API",
 *   version="1.0.0",
 *   description="Микросервис массовой отправки уведомлений (SMS / Email).<br><br>
 * **Основные возможности:**
 * - Bulk-отправка уведомлений нескольким получателям за один запрос
 * - Идемпотентность на уровне API (idempotency_key) и воркера (Redis SET NX)
 * - Два приоритета: `transactional` (priority 10) и `marketing` (priority 1)
 * - Автоматические ретраи при временных сбоях провайдера
 * - История уведомлений по подписчику с фильтрами по статусу и каналу"
 * )
 *
 * @OA\Server(url=L5_SWAGGER_CONST_HOST, description="Текущий сервер")
 */
class HealthController extends Controller
{
    /**
     * Liveness-проба — всегда возвращает 200, пока процесс жив.
     *
     * @OA\Get(
     *   path="/health",
     *   summary="Liveness probe",
     *   @OA\Response(response=200, description="Service is alive")
     * )
     */
    public function liveness(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness-проба — 200, когда все зависимости доступны; 503 при сбое хотя бы одной.
     *
     * @OA\Get(
     *   path="/ready",
     *   summary="Readiness probe",
     *   @OA\Response(response=200, description="All dependencies healthy"),
     *   @OA\Response(response=503, description="One or more dependencies unavailable")
     * )
     */
    public function readiness(): JsonResponse
    {
        $checks = [];

        // PostgreSQL
        try {
            DB::select('SELECT 1');
            $checks['postgres'] = 'ok';
        } catch (Throwable $e) {
            $checks['postgres'] = 'error: ' . $e->getMessage();
        }

        // Redis
        try {
            app('redis')->ping();
            $checks['redis'] = 'ok';
        } catch (Throwable $e) {
            $checks['redis'] = 'error: ' . $e->getMessage();
        }

        // RabbitMQ
        // Параметры соединения берём из уже разобранного конфига queue.php,
        // чтобы не дублировать парсинг RABBITMQ_URL.
        try {
            $rmq  = config('queue.connections.rabbitmq.hosts.0', []);
            $conn = new AMQPStreamConnection(
                host:               (string) ($rmq['host']     ?? 'localhost'),
                port:               (int)    ($rmq['port']     ?? 5672),
                user:               (string) ($rmq['user']     ?? 'guest'),
                password:           (string) ($rmq['password'] ?? 'guest'),
                vhost:              (string) ($rmq['vhost']    ?? '/'),
                connection_timeout: 3.0,
                read_write_timeout: 3.0,
            );
            $conn->close();
            $checks['rabbitmq'] = 'ok';
        } catch (Throwable $e) {
            $checks['rabbitmq'] = 'error: ' . $e->getMessage();
        }

        $allOk  = ! in_array(false, array_map(
            fn ($v) => $v === 'ok',
            $checks,
        ), true);
        $status = $allOk ? 200 : 503;

        return response()->json(['status' => $allOk ? 'ok' : 'degraded', 'checks' => $checks], $status);
    }
}
