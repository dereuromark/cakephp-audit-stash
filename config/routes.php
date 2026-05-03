<?php

declare(strict_types=1);

/**
 * No-op stub. The plugin's routes are defined in
 * `AuditStashPlugin::routes()` so the host application can hook them in
 * via `addPlugin('AuditStash', ['routes' => true])`. This file exists
 * only to satisfy `BaseApplication::routes()` which require()s it.
 */
return function (): void {};
