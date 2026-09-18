import ContextMenuActions from '@typo3/backend/context-menu-actions.js';
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

export default class UsageActions {
    static disableRecord(table, uid, data) {
        const modal = Modal.confirm(data.title, data.message, Severity.warning, [
            {text: data.buttonCloseText, active: true, btnClass: 'btn-default', name: 'cancel'},
            {text: data.buttonOkText, btnClass: 'btn-warning', name: 'hide'},
        ]);
        modal.addEventListener('button.clicked', event => {
            if (event.target.getAttribute('name') === 'hide') {
                ContextMenuActions.disableRecord(table, uid, data);
            }
            modal.hideModal();
        });
    }
}
