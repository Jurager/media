<?php

namespace Jurager\Media\Enums;

enum ConversionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
