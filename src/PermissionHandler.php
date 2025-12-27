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
    /**
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourseUser(CourseStub $course): string{
        return "domain;{$course->domain->domain};course;{$course->id};user;*";
    }

    /**
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourse(CourseStub $course): string{
        return "domain;{$course->domain->domain};course;{$course->id};*";
    }

    /**
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomainUser(Domain $domain): string{
        return "domain;{$domain->domain};user;*";
    }

    /**
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomain(Domain $domain): string{
        return "domain;{$domain->domain};*";
    }

    /**
     * @param Domain $domain
     * @return Permission
     */
    public static function domainPermission(Domain $domain): string{
        return "domain;{$domain->domain}";
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
     * @return PermissionType
     */
    public static function domainType(): string{
        return "domain";
    }
    /**
     * @return PermissionType
     */
    public static function domainCourseType(): string{
        return "domain;course";
    }
    /**
     * @return PermissionType
     */
    public static function domainCourseUserType(): string{
        return "domain;course;user";
    }
    /**
     * @return PermissionType
     */
    public static function domainUserType(): string{
        return "domain;user";
    }
    /**
     * @return PermissionType
     */
    public static function globalType(): string{
        return "global";
    }
    
}