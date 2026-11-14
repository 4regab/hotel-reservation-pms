<?php
declare(strict_types=1);

namespace Lib;

interface IdempotencyStore
{
    /** @return array{request_hash:string,status:int,body:string,created_at:int}|null */
    public function find(string $key, string $caller): ?array;

    public function save(string $key, string $caller, string $requestHash, int $status, string $body, int $now): void;
}
