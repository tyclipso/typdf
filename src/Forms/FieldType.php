<?php

declare(strict_types=1);

namespace Typdf\Forms;

enum FieldType: string
{
    case Text        = 'text';
    case Checkbox    = 'checkbox';
    case Radio       = 'radio';
    case Select      = 'select';
    case PushButton  = 'push_button';
}
