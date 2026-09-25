<?php

declare(strict_types=1);

namespace Faoxima\Provisioning;

interface DatabaseAdmin
{
    /** @return array{name:string,user:string,password:string} */
    public function create(string $botName): array;
    public function exists(string $botName): bool;
    /** @param array{name:string,user:string,password:string} $credentials */
    public function dump(array $credentials, string $destination): void;
    public function restore(string $botName, string $source, string $password): void;
    public function drop(string $botName, string $password): void;
}

interface TelegramGateway
{
    /** @return array{id:int,username:string} */
    public function getMe(string $token): array;
    public function setWebhook(string $token, string $url, string $secret): void;
    public function deleteWebhook(string $token): void;
    /** @return array{url:string,pending_update_count:int} */
    public function getWebhookInfo(string $token): array;
}
