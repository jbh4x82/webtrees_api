<?php

/**
 * webtrees_api — token-gated JSON API for abigfamily.org (Meran tree).
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Module;

require_once __DIR__ . '/ApiModule.php';

// Must return an object implementing ModuleCustomInterface.
return new ApiModule();
