<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Frontend\Typolink\UnableToLinkException;

final class LocalRenderer
{
    public function __construct(private readonly DestinationStore $destinations, private readonly PeerConfiguration $configuration) {}

    public function url(array $details, ServerRequestInterface $request, string $text): string
    {
        if ((new ManagedLink())->resolveHandlerData($details)['valid']) {
            $request->getAttribute('frontend.cache.collector')?->addCacheTags(new CacheTag(DestinationStore::tag($details), 300), new CacheTag(DestinationStore::peerTag($details['instance']), 300));
            $destination = $this->destinations->find($details);
            if ($destination && in_array($destination['status'], ['resolved', 'stale'], true)) {
                try {
                    foreach ($this->configuration->load()['outgoing'] ?? [] as $peer) {
                        if (($peer['enabled'] ?? false) === true && ($peer['instance'] ?? '') === $details['instance']
                            && PeerConfiguration::allowsUrl($destination['url'], $peer['origins'] ?? [])) {
                            // Per-link suffixes are kept out of the shared destination URL.
                            return $destination['url']
                                . (isset($details['query']) ? '?' . $details['query'] : '')
                                . (isset($details['fragment']) ? '#' . $details['fragment'] : '');
                        }
                    }
                } catch (\RuntimeException|\InvalidArgumentException|\JsonException) {
                    // Disabled or invalid clone configuration fails closed.
                }
            }
        }
        throw new UnableToLinkException('Managed destination is unavailable.', linkText: $text);
    }
}
