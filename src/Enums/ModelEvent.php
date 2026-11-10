<?php

namespace TraceLog\Enums;

/**
 * Eloquent model events captured by TraceLog.
 */
enum ModelEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case ForceDeleted = 'forceDeleted';
}
