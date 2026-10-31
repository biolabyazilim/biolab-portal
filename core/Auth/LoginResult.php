<?php
declare(strict_types=1);

namespace Biolab\Core\Auth;

final class LoginResult
{
    /** @param array{userId:int, username:string, fullName:string, companyId:int, companyName:string}|null $user */
    private function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly ?array $user = null,
    ) {}

    /** @param array{userId:int, username:string, fullName:string, companyId:int, companyName:string} $user */
    public static function ok(array $user): self
    {
        return new self(true, null, $user);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
