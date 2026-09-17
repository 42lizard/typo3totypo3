<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class EditWarnings implements FormDataProviderInterface
{
    private array $seen = [];

    public function __construct(private readonly LinkReport $report, private readonly FlashMessageService $messages) {}

    public function addData(array $result): array
    {
        if ($result['command'] !== 'edit') { return $result; }
        $table = $result['tableName'];
        $uid = (int)$result['databaseRow']['uid'];
        $key = $table . ':' . $uid;
        if (isset($this->seen[$key])) { return $result; }
        $this->seen[$key] = true;
        $rows = $this->report->page(0, $table, $uid)['rows'];
        if ($rows) {
            $fields = implode(', ', array_unique(array_column($rows, 'field')));
            $this->messages->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                Labels::text('warning.edit', [$fields]),
                Labels::text('module.title'), ContextualFeedbackSeverity::WARNING,
            ));
        }
        return $result;
    }
}
