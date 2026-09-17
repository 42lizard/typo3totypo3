<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;

/** A persistent, read-only signal on backend reload; no email or repeated flash notifications. */
final class ConnectionToolbar implements ToolbarItemInterface
{
    public function __construct(private readonly LinkReport $report, private readonly UriBuilder $uris, private readonly IconFactory $icons) {}

    public function checkAccess(): bool
    {
        return $this->report->connections() !== [];
    }

    public function getItem(): string
    {
        if (!$this->checkAccess()) { return ''; }
        $label = htmlspecialchars(Labels::text('warning.connections'), ENT_QUOTES, 'UTF-8');
        return '<span class="toolbar-item-icon" title="' . $label . '">'
            . $this->icons->getIcon('actions-link', IconSize::SMALL)->render()
            . '</span><span class="toolbar-item-title">' . $label . '</span>';
    }

    public function hasDropDown(): bool { return true; }

    public function getDropDown(): string
    {
        if (!$this->checkAccess()) { return ''; }
        $url = htmlspecialchars((string)$this->uris->buildUriFromRoute(LinkReport::MODULE), ENT_QUOTES, 'UTF-8');
        return '<p class="dropdown-headline">' . htmlspecialchars(Labels::text('warning.connections')) . '</p>'
            . '<a class="dropdown-item" data-moduleroute-identifier="exchange_links" href="' . $url . '">' . htmlspecialchars(Labels::text('action.openReport')) . '</a>';
    }

    public function getAdditionalAttributes(): array { return []; }
    public function getIndex(): int { return 55; }
}
