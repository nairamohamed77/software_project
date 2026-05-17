<?php

declare(strict_types=1);

namespace CareNest\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use User;

/** Authentication and authorization focused tests. */
final class ProjectDomainTest extends TestCase
{
    /** 1) Login email normalization matches auth flow expectations. */
    #[DataProvider('loginEmailProvider')]
    public function testNormalizeLoginEmail(string $input, string $expected): void
    {
        self::assertSame($expected, User::normalizeLoginEmail($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function loginEmailProvider(): iterable
    {
        yield 'trim + lowercase' => ['  USER@Example.COM ', 'user@example.com'];
        yield 'already normalized' => ['pal@carenest.com', 'pal@carenest.com'];
    }

    /** 2) Account must be active to authenticate. */
    #[DataProvider('loginAllowedProvider')]
    public function testIsAccountLoginAllowed(array $user, bool $expected): void
    {
        self::assertSame($expected, User::isAccountLoginAllowed($user));
    }

    /** @return iterable<string, array{array<string,mixed>, bool}> */
    public static function loginAllowedProvider(): iterable
    {
        yield 'active flag + active status' => [['is_active' => 1, 'account_status' => 'Active'], true];
        yield 'active flag false' => [['is_active' => 0, 'account_status' => 'Active'], false];
        yield 'status inactive' => [['is_active' => 1, 'account_status' => 'Inactive'], false];
        yield 'status with spaces/case' => [['is_active' => 1, 'account_status' => '  aCtIvE  '], true];
    }

    /** 3) Role spec parsing supports both "|" and "," as in requireRole(). */
    #[DataProvider('roleSpecProvider')]
    public function testParseAllowedRoles(string $spec, array $expected): void
    {
        self::assertSame($expected, User::parseAllowedRoles($spec));
    }

    /** @return iterable<string, array{string, array<int,string>}> */
    public static function roleSpecProvider(): iterable
    {
        yield 'pipe separator' => ['Admin|Senior', ['Admin', 'Senior']];
        yield 'comma separator with spaces' => ['Admin, FamilyProxy', ['Admin', 'FamilyProxy']];
        yield 'mixed separators and empties' => ['Admin|,Senior,,', ['Admin', 'Senior']];
    }

    /** 4) Role authorization check mirrors route guard behavior. */
    #[DataProvider('roleAllowedProvider')]
    public function testRoleIsAllowed(string $currentRole, string $spec, bool $expected): void
    {
        self::assertSame($expected, User::roleIsAllowed($currentRole, $spec));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function roleAllowedProvider(): iterable
    {
        yield 'allowed via pipe' => ['Admin', 'Admin|Senior', true];
        yield 'allowed via comma' => ['FamilyProxy', 'Senior,FamilyProxy', true];
        yield 'not allowed' => ['Pal', 'Admin|Senior', false];
    }

    /** 5) Dashboard path is role-based after successful login. */
    #[DataProvider('dashboardPathProvider')]
    public function testDashboardPathForKnownRoles(string $role, string $expectedPath): void
    {
        self::assertSame($expectedPath, User::dashboardPathFor(['role_type' => $role]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function dashboardPathProvider(): iterable
    {
        yield 'senior dashboard' => ['Senior', 'views/senior/dashboard.php'];
        yield 'pal dashboard' => ['Pal', 'views/pal/dashboard.php'];
        yield 'proxy dashboard' => ['FamilyProxy', 'views/proxy/dashboard.php'];
        yield 'admin dashboard' => ['Admin', 'views/admin/dashboard.php'];
    }

    /** 6) Unknown role is denied and routed to 403 page. */
    public function testDashboardPathForUnknownRoleFallsBackTo403(): void
    {
        self::assertSame(
            'views/shared/error.php?code=403',
            User::dashboardPathFor(['role_type' => 'Guest'])
        );
    }
}
