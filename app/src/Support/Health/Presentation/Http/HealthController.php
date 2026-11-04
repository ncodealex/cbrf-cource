<?php

declare(strict_types=1);

namespace App\Support\Health\Presentation\Http;

use App\Support\Health\Application\ReadinessChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class HealthController
{
    public function __construct(
        private ReadinessChecker $readinessChecker,
    ) {
    }

    /**
     * Liveness: процесс обрабатывает HTTP-запросы. Внешние зависимости не проверяются.
     */
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok'], Response::HTTP_OK);
    }

    /**
     * Readiness: обязательные зависимости доступны, экземпляр готов принимать трафик.
     */
    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $report = $this->readinessChecker->check();

        return $report->isReady()
            ? $this->json(['status' => 'ok', 'checks' => $report->checks], Response::HTTP_OK)
            : $this->json(['status' => 'not_ready', 'checks' => $report->checks], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(
        array $data,
        int $status,
    ): JsonResponse {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
