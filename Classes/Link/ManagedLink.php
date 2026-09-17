<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\LinkHandling\LinkHandlingInterface;

/** Stable identity plus the link author's query and fragment, never a database UID. */
final class ManagedLink implements LinkHandlingInterface
{
    public function asString(array $parameters): string
    {
        $data = $this->resolveHandlerData($parameters);
        if (!$data['valid']) {
            throw new \InvalidArgumentException('Invalid managed link.');
        }
        $query = array_intersect_key($data, array_flip(['instance', 'page', 'language', 'query']));
        return 't3://exchange?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            . (isset($parameters['fragment']) ? '#' . $parameters['fragment'] : '');
    }

    public function resolveHandlerData(array $data): array
    {
        $language = $data['language'] ?? null;
        $valid = PeerConfiguration::isUuid($data['instance'] ?? null)
            && PeerConfiguration::isUuid($data['page'] ?? null)
            && (is_int($language) || (is_string($language) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $language)))
            && (int)$language >= 0 && (int)$language <= 2147483647;
        foreach (['query', 'fragment'] as $suffix) {
            if (isset($data[$suffix]) && (!is_string($data[$suffix]) || preg_match('/[\x00-\x20\x7f\\\\]/', $data[$suffix]))) {
                $valid = false;
            }
        }
        return ['valid' => (bool)$valid, 'instance' => $data['instance'] ?? '', 'page' => $data['page'] ?? '',
            'language' => $valid ? (int)$language : 0] + array_intersect_key($data, array_flip(['query', 'fragment']));
    }

    public static function key(array $reference): string
    {
        return hash('sha256', $reference['instance'] . ':' . $reference['page'] . ':' . $reference['language']);
    }
}
