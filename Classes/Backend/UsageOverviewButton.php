<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class UsageOverviewButton
{
    public function __construct(private readonly UsageReport $usage, private readonly UriBuilder $uris, private readonly IconFactory $icons) {}

    #[AsEventListener]
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        // v13's event predates getRequest().
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $edit = $request?->getQueryParams()['edit']['pages'] ?? null;
        if (!is_array($edit) || count($edit) !== 1 || reset($edit) !== 'edit') { return; }
        $page = filter_var(key($edit), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false || $this->usage->warning($page) === null) { return; }
        $factory = \TYPO3\CMS\Backend\Template\Components\ComponentFactory::class;
        $button = class_exists($factory) ? GeneralUtility::makeInstance($factory)->createLinkButton() : $event->getButtonBar()->makeLinkButton();
        $button->setHref((string)$this->uris->buildUriFromRoute(LinkReport::MODULE, ['view' => 'usage', 'id' => $page, 'descendants' => '1']))
            ->setTitle(Labels::text('usage.title'))->setShowLabelText(true)
            ->setIcon($this->icons->getIcon('actions-link', IconSize::SMALL));
        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_RIGHT][30][] = $button;
        $event->setButtons($buttons);
    }
}
