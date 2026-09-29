<?php

namespace App\Domain\Video\Enums;

enum AspectRatio: string
{
    case Vertical = '9:16';
    case Square = '1:1';
    case Landscape = '16:9';
}
