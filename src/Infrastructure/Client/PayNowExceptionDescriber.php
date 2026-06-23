<?php

declare(strict_types=1);

/**
 * @copyright 2026 Crehler Sp. z o.o.
 * @link https://crehler.com/
 * @license proprietary
 * support@crehler.com
 */

namespace Crehler\PayNow\Infrastructure\Client;

use Paynow\Exception\PaynowException;
use Paynow\HttpClient\HttpClientException;
use Throwable;

use function implode;
use function sprintf;

/**
 * Flattens a PayNow SDK exception into a single admin-readable string.
 *
 * PaynowException's top-level message is generic ("Error occurred during processing
 * request"); the actionable reason lives in its structured errors (errorType +
 * message) or in the chained HTTP response body. Both the refund provider and the
 * connection checker need this, so the unwrapping lives here instead of being copied.
 */
final class PayNowExceptionDescriber
{
    public function describe(Throwable $e): string
    {
        if ($e instanceof PaynowException && $e->getErrors() !== []) {
            $parts = [];
            foreach ($e->getErrors() as $error) {
                $parts[] = sprintf('%s: %s', $error->getType(), $error->getMessage());
            }

            return implode('; ', $parts);
        }

        $previous = $e->getPrevious();
        if ($previous instanceof HttpClientException) {
            return sprintf(
                'HTTP %s: %s',
                $previous->getStatus() ?? '?',
                $previous->getBody() ?? $previous->getMessage(),
            );
        }

        return $e->getMessage();
    }
}
