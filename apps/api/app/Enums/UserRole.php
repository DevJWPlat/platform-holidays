<?php

namespace App\Enums;

enum UserRole: string
{
    case Employee = 'employee';
    case Approver = 'approver';
    case DepartmentManager = 'department_manager';
    case Administrator = 'administrator';
}
