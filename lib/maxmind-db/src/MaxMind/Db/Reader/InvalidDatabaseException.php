<?php
// phpcs:ignoreFile -- third-party vendor library (MaxMind DB Reader Apache-2.0); not subject to WP coding standards

declare(strict_types=1);

namespace Cspv\MaxMind\Db\Reader;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * This class should be thrown when unexpected data is found in the database.
 */
// phpcs:disable
class InvalidDatabaseException extends \Exception {}
