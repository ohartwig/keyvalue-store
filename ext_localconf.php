<?php

defined('TYPO3') or exit;

call_user_func(static function () {
    // Register as a locking strategy option.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy::class] ??= [
        'options' => [],
    ];
});
