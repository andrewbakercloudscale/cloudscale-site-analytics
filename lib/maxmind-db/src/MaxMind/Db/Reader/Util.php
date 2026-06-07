<?php
// phpcs:ignoreFile -- third-party vendor library (MaxMind DB Reader Apache-2.0); not subject to WP coding standards

declare(strict_types=1);

namespace MaxMind\Db\Reader;

class Util
{
    /**
     * @param resource    $stream
     * @param int<0, max> $numberOfBytes
     */
    public static function read($stream, int $offset, int $numberOfBytes): string
    {
        if ($numberOfBytes === 0) {
            return '';
        }
        if (fseek($stream, $offset) === 0) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- vendor library
            $value = fread($stream, $numberOfBytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- vendor library native file op

            // We check that the number of bytes read is equal to the number
            // asked for. We use ftell as getting the length of $value is
            // much slower.
            if ($value !== false && ftell($stream) - $offset === $numberOfBytes) {
                return $value;
            }
        }

        throw new InvalidDatabaseException(
            'The MaxMind DB file contains bad data'
        );
    }
}
