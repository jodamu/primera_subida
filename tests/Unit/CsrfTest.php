<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGeneratedTokenCanBeVerified(): void
    {
        $token = Csrf::token();

        self::assertSame(64, strlen($token));
        self::assertTrue(Csrf::verify($token));
    }

    public function testTokenIsRejectedWhenItDoesNotMatch(): void
    {
        Csrf::token();

        self::assertFalse(Csrf::verify(str_repeat('0', 64)));
        self::assertFalse(Csrf::verify(null));
    }
}
