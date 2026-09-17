<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\Backend\Labels;
use TYPO3\CMS\Backend\Form\Event\ModifyLinkExplanationEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

final class LinkExplanation
{
    public function __construct(private readonly DestinationStore $destinations, private readonly IconFactory $icons) {}

    #[AsEventListener(identifier: 'typo3-to-typo3/link-explanation')]
    public function __invoke(ModifyLinkExplanationEvent $event): void
    {
        $reference = $event->getLinkData();
        if (($reference['type'] ?? '') !== 'exchange') {
            return;
        }
        $destination = (new ManagedLink())->resolveHandlerData($reference)['valid']
            ? $this->destinations->find($reference) : null;
        $text = Labels::text('explanation.missing');
        if ($destination) {
            $text = $destination['url']
                . (isset($reference['query']) ? '?' . $reference['query'] : '')
                . (isset($reference['fragment']) ? '#' . $reference['fragment'] : '');
            if ($destination['status'] !== 'resolved') {
                $text .= ' (' . match ($destination['status']) {
                    'stale' => Labels::text('explanation.stale'),
                    'denied' => Labels::text('explanation.denied'),
                    default => Labels::text('explanation.unavailable'),
                } . ')';
            }
        }
        // FormEngine escapes the text. Keep its existing attribute explanation intact.
        $event->setLinkExplanationValue('text', $text);
        $event->setLinkExplanationValue('icon', $this->icons->getIcon('actions-link', IconSize::SMALL)->render());
    }
}
