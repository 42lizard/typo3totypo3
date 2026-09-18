<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ProviderInterface;

/** Add warnings only to actions already authorized by TYPO3's page provider. */
final class UsageContextMenu implements ProviderInterface
{
    private int $pageId = 0;

    public function __construct(private readonly UsageReport $usage, private readonly \TYPO3\CMS\Backend\Routing\UriBuilder $uris) {}

    public function setContext(string $table, string $identifier, string $context = ''): void
    {
        $this->pageId = $table === 'pages' && ctype_digit($identifier) ? (int)$identifier : 0;
    }

    public function canHandle(): bool { return $this->pageId > 0; }

    public function getPriority(): int { return 55; }

    public function addItems(array $items): array
    {
        if (!$this->canHandle() || !array_intersect(['delete', 'disable'], array_keys($items))) { return $items; }
        $warning = $this->usage->warning($this->pageId);
        if ($warning === null) { return $items; }
        $url = (string)$this->uris->buildUriFromRoute(LinkReport::MODULE, ['view' => 'usage', 'id' => $this->pageId, 'descendants' => '1']);
        $message = htmlspecialchars($warning) . ' <a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars(Labels::text('usage.title')) . '</a>';
        foreach (['delete', 'disable'] as $action) {
            if (!isset($items[$action])) { continue; }
            $attributes = $items[$action]['additionalAttributes'] ?? [];
            $attributes['data-title'] ??= Labels::text('usage.title');
            $attributes['data-message'] = trim(($attributes['data-message'] ?? '') . "\n\n" . $message);
            if ($action === 'disable') {
                $attributes['data-callback-module'] = '@lizard/typo3-to-typo3/usage-actions';
                $attributes['data-button-close-text'] = Labels::text('usage.cancel');
                $attributes['data-button-ok-text'] = Labels::text('usage.hideAnyway');
            }
            $items[$action]['additionalAttributes'] = $attributes;
        }
        return $items;
    }
}
