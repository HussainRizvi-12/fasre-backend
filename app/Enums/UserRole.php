<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Faculty = 'faculty';
    case Student = 'student';
}
