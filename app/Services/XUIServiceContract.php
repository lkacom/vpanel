<?php

namespace App\Services;

interface XUIServiceContract
{
    public function login(): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInbounds(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClients(int $inboundId): array;

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function addClient(int $inboundId, array $clientData): array;

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function updateClient(int $inboundId, string $clientId, array $clientData): array;
}
