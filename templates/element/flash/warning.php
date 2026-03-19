<?php
/**
 * Warning flash message element
 *
 * @var \Cake\View\View $this
 */

$flash = $this->getRequest()->getSession()->read('Flash.flash');
if (!$flash) {
    return;
}

foreach ($flash as $message) {
    if (!isset($message['element']) || $message['element'] !== 'flash/warning') {
        continue;
    }
    ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= h($message['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
}
