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
     * Returns only those permissions that exist in the same context as the given filter.
     * Ie, student-bound items exist in the context of a domain name and course id. 
     * This method returns only those permissions which are for that same context, 
     * ie all other permissions to see students in that course on that domain.
     * @param ContextFilter $context A context filter
     * @param Permission[] $permissions Permissions to filter
     * @return Permission[] Filtered permissions
     */
    public static function filterOnContext(mixed $context, array $permissions): array{
        //TODO
    }

    /**
     * Filters a given list of permissions to only those of a certain type
     * @param PermissionType $contextType The context type to filter on.
     * @param Permission[] $permissions The permissions to filter
     * @return Permission[] Filtered permissions
     */
    public static function filterOnType(mixed $contextType, array $permissions) : array{
        //TODO
    }

    /**
     * Summary of contextFrom
     * @param PermissionType $permission
     * @return ContextFilter
     */
    public static function contextFrom(mixed $permission): string{
        //TODO
        
    }

    /**
     * Summary of contextFilterDomainCourseUser
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourseUser(CourseStub $course): string{
        //TODO
        
    }

    /**
     * Summary of contextFilterDomainCourse
     * @param CourseStub $course
     * @return ContextFilter
     */
    public static function contextFilterDomainCourse(CourseStub $course): string{
        //TODO
        
    }

    /**
     * Summary of contextFilterDomainUser
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomainUser(Domain $domain): string{
        //TODO
        
    }

    /**
     * Summary of contextFilterDomain
     * @param Domain $domain
     * @return ContextFilter
     */
    public static function contextFilterDomain(Domain $domain): string{
        //TODO
        
    }

    /**
     * Summary of domainPermission
     * @param Domain $domain
     * @return Permission
     */
    public static function domainPermission(Domain $domain): string{
        //TODO
        
    }
    
    /**
     * Summary of domainCoursePermission
     * @param CourseStub $course
     * @return Permission
     */
    public static function domainCoursePermission(CourseStub $course): string{
        //TODO
        
    }

    /**
     * Summary of domainUserPermission
     * @param UserStub $user
     * @return Permission
     */
    public static function domainUserPermission(UserStub $user): string{
        //TODO
        
    }

    /**
     * Summary of domainCourseUserPermission
     * @param CourseStub $course
     * @param UserStub $user
     * @return Permission
     */
    public static function domainCourseUserPermission(CourseStub $course, UserStub $user): string{
        //TODO
        
    }

    /**
     * Summary of typeFromPermission
     * @param string $permission
     * @return PermissionType
     */
    public static function typeFromPermission(mixed $permission) : string{
        //TODO
        
    }

    /**
     * Summary of typeFromContextFilter
     * @param string $contextFilter
     * @return PermissionType
     */
    public static function typeFromContextFilter(mixed $contextFilter) : string{
        //TODO
        
    }

    /**
     * Summary of domainType
     * @return PermissionType
     */
    public static function domainType(): string{
        //TODO
        
    }
    /**
     * Summary of domainCourseType
     * @return PermissionType
     */
    public static function domainCourseType(): string{
        //TODO
        
    }
    /**
     * Summary of domainCourseUserType
     * @return PermissionType
     */
    public static function domainCourseUserType(): string{
        //TODO
        
    }
    /**
     * Summary of domainUserType
     * @return PermissionType
     */
    public static function domainUserType(): string{
        //TODO
        
    }
    /**
     * Summary of globalType
     * @return PermissionType
     */
    public static function globalType(): string{
        //TODO
        
    }
    
}