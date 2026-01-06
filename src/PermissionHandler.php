<?php

namespace CanvasApiLibrary\RedisCacheProvider;

use CanvasApiLibrary\Caching\AccessAware\Interfaces\PermissionsHandlerInterface;
use CanvasApiLibrary\Core\Models\CourseStub;
use CanvasApiLibrary\Core\Models\Domain;
use CanvasApiLibrary\Core\Models\UserStub;

/**
 * @phpstan-type Permission string
 * @phpstan-type ContextFilter string
 * @phpstan-type PermissionType string
 * @implements PermissionsHandlerInterface<Permission, ContextFilter, PermissionType>
 */
class PermissionHandler implements PermissionsHandlerInterface{
    private static function domainHash(Domain $domain): string{
        return hash('sha256', $domain->domain);
    }

    /**
     * Lua pattern to match a specific domain and course, with any user
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourseAnyUser(CourseStub $course): string{
        $escapedDomain = self::domainHash($course->domain);
        return "domain;{$escapedDomain};course;{$course->id};user;%d+$";
    }

    /**
     * Lua pattern to match a specific domain with any user
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomainAnyUser(Domain $domain): string{
        $escapedDomain = self::domainHash($domain);
        return "domain;{$escapedDomain};user;%d+$";
    }
    
    /**
     * @param CourseStub $course
     * @return Permission
     */
    public static function domainCoursePermission(CourseStub $course): string{
        return "domain;{$course->domain->domain};course;{$course->id}";
    }

    /**
     * @param UserStub $user
     * @return Permission
     */
    public static function domainUserPermission(UserStub $user): string{
        return "domain;{$user->domain->domain};user;{$user->id}";
    }

    /**
     * @param CourseStub $course
     * @param UserStub $user
     * @return Permission
     */
    public static function domainCourseUserPermission(CourseStub $course, UserStub $user): string{
        return "domain;{$course->domain->domain};course;{$course->id};user;{$user->id}";
    }

    /**
     * Lua pattern for matching Domain-Course permissions
     * @return PermissionType
     */
    public static function domainCourseType(): string{
        return "domain;%w+;course;%d+$";
    }
    /**
     * Lua pattern for matching Domain-Course-User permissions
     * @return PermissionType
     */
    public static function domainCourseUserType(): string{
        return "domain;%w+;course;%d+;user;%d+$";
    }
    /**
     * Lua pattern for matching Domain-User permissions
     * @return PermissionType
     */
    public static function domainUserType(): string{
        return "domain;%w+;user;%d+$";
    }

    /**
     * Lua pattern that matches all permission types including personal ones.
     * Used for item unions.
     * @return string
     */
    public static function everyPermissionTypePattern(): string{
        return ".+$";
    }

    public static function clientPermission(string $clientID): string{
        //hash the clientID to avoid any special character issues
        $clientHash = hash('sha256', $clientID);
        return "client;{$clientHash}";
    }
}