<?php

namespace TraceLog\Concerns;

/**
 * Marks an Eloquent model for automatic tracing.
 *
 * Models using this trait are logged on create, update, and delete without
 * needing to call TraceLog::watch().
 */
trait Traceable {}
