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
        $escapedDomain = addcslashes($course->domain->domain, '\\*?[]');
        return "domain;{$escapedDomain};course;{$course->id};user;[0-9]*";
    }
//TODO check of alle filters kloppen. Moet domain bv filteren op 1 domain of alle domains?
//Anders ff namen in interface aanpassen voor duidelijkheid.
    /**
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourse(CourseStub $course): string{
        $escapedDomain = addcslashes($course->domain->domain, '\\*?[]');
        return "domain;{$escapedDomain};course;{$course->id}";
    }

    /**
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomainUser(Domain $domain): string{
        $escapedDomain = addcslashes($domain->domain, '\\*?[]');
        return "domain;{$escapedDomain};user;[0-9]*";
    }

    /**
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomain(Domain $domain): string{
        $escapedDomain = addcslashes($domain->domain, '\\*?[]');
            return "domain;{$escapedDomain}";
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
    
    public static function filterPermissionsToContext(mixed $contextFilter, array $permissions): array{
        if(!$contextFilter[-1] === '*'){
            throw new \InvalidArgumentException("Context filter must end with a wildcard '*'");
        }
        $base = substr($contextFilter, 0, -1);
        $semicoloncount = substr_count($base, ';');
        $filtered = [];
        foreach($permissions as $perm){
            if(str_starts_with($perm, $base) && substr_count($perm, ';') === $semicoloncount){
                $filtered[] = $perm;
            }
        }
        return $filtered;
    }

    public static function typeFromPermission(mixed $permission): string{
        $parts = explode(';', $permission);
        //return all uneven parts joined by semicolon
        $filtered = array_filter($parts, fn($k) => $k % 2 === 0, ARRAY_FILTER_USE_KEY);
        return implode(';', $filtered);
    }
}