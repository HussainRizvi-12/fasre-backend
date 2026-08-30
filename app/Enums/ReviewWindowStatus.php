<?php

namespace App\Enums;

enum ReviewWindowStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case Published = 'published';
}
