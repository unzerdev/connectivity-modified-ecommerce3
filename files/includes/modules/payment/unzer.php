<?php declare(strict_types=1);

final class unzer
{
    public string $code = 'unzer';
    public ?string $title;
    public ?string $description;
    public ?bool $enabled;
    public ?int $sort_order;
    public ?string $info;
    public ?bool $check;
    public bool $tmpOrders = true;
    public int $tmpStatus = 1;
    public string $form_action_url = '';


    public function __construct()
    {
        $this->title = UnzerConfigHelper::getConstant('MODULE_PAYMENT_UNZER_TEXT_TITLE');
        if(defined('RUN_MODE_ADMIN') && RUN_MODE_ADMIN){
            $this->extended_description = '<b>Version</b><br />v'.UnzerConstants::MODULE_VERSION;
        }
        $this->description = UnzerConfigHelper::getConstant('MODULE_PAYMENT_UNZER_TEXT_DESCRIPTION');
        $this->info = UnzerConfigHelper::getConstant('MODULE_PAYMENT_UNZER_TEXT_INFO');
        $this->sort_order = (int)UnzerConfigHelper::getConstant('MODULE_PAYMENT_UNZER_SORT_ORDER');
        $this->enabled = filter_var(UnzerConfigHelper::getConstant('MODULE_PAYMENT_UNZER_STATUS'), FILTER_VALIDATE_BOOLEAN);

        $this->form_action_url = xtc_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL');
    }


    // class methods
    public function javascript_validation()
    {
        return false;
    }


    public function selection()
    {
        return ['id' => $this->code, 'module' => $this->title, 'description' => $this->info];
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return [];
    }


    public function process_button()
    {
        return false;
    }


    public function before_process()
    {
        if (!empty($_SESSION['unzer_payment_id']) && !empty($_SESSION['tmp_oID'])) {
            //this is a redirect from an unzer payment process
            $orderId = $_SESSION['tmp_oID'];
            $paymentId = (string)$_SESSION['unzer_payment_id'];

            $unzerApiHelper = new UnzerApiHelper();
            $payment = $unzerApiHelper->fetchPayment($paymentId);
            if ($payment === null) {
                UnzerCheckoutHelper::doErrorRedirect(UnzerConfigHelper::getStringConstant('UNZER_GENERIC_ERROR_MESSAGE'));
            }
            if (!($payment->isPending() || $payment->isCompleted())) {
                UnzerCheckoutHelper::doErrorRedirect(UnzerConfigHelper::getStringConstant('UNZER_GENERIC_ERROR_MESSAGE'));
            }
            $unzerOrderHelper = new UnzerOrderHelper();
            $unzerOrderHelper->writePaymentIdAndPaymentMethod($orderId, $paymentId, $_SESSION['unzer_payment_method'] ?? '');

            $isCharged = false;
            $charge = $payment->getChargeByIndex(0);
            if ($charge !== null) {
                if ($charge->isSuccess()) {
                    $isCharged = true;
                    $unzerOrderHelper->setOrderStatusCaptured($orderId, 'Checkout');
                }
            }

            if (!$isCharged) {
                $authorization = $payment->getAuthorization();
                if ($authorization !== null && $authorization->isSuccess()) {
                    $isAuthorized = true;
                    $unzerOrderHelper->setOrderStatusAuthorized($orderId, 'Checkout');
                }
            }

        }
    }


    public function after_process()
    {
        unset($_SESSION['unzer_payment_id']);
        unset($_SESSION['unzer_payment_method']);
    }

    public function get_error()
    {
        UnzerConfigHelper::initTexts();
        return [
            'error' => UnzerConfigHelper::getStringConstant('UNZER_GENERIC_ERROR_MESSAGE'),
        ];
    }


    public function output_error()
    {
        return false;
    }

    public function payment_action()
    {
        $orderId = $GLOBALS['insert_id'];
        $_SESSION['unzer_order_id'] = $orderId;
        xtc_redirect(xtc_href_link('unzer_checkout.php', '', 'SSL'));
    }

    public function check()
    {
        if (!isset ($this->check)) {
            $this->check = UnzerConfigHelper::getConfigValueFromDb('MODULE_PAYMENT_UNZER_STATUS') !== null;
        }

        return $this->check;
    }

    public function install()
    {
        $switcherType = [
            '',
            'xtc_cfg_select_option(array(\'true\', \'false\'),',
        ];

        $orderStatusType = [
            'xtc_get_order_status_name',
            'xtc_cfg_pull_down_order_statuses_with_empty(',
        ];

        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_STATUS', 'false', $switcherType, '10');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_SORT_ORDER', '0', null, '20');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_PUBLIC_KEY', '', null, '30');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_PRIVATE_KEY', '', null, '40');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ALIAS', 'UNZ');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_AUTHORIZED', '', $orderStatusType, '100');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_CAPTURED', '', $orderStatusType, '110');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_CHARGEBACK', '', $orderStatusType, '120');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_REFUNDED', '', $orderStatusType, '130');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_TRIGGER_REFUND', '4', $orderStatusType, '140');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_TRIGGER_CAPTURE', '3', $orderStatusType, '150');
        UnzerConfigHelper::createConfigValue('MODULE_PAYMENT_UNZER_ALLOWED', '', null, '140');

        $result = xtc_db_query("SHOW COLUMNS FROM `orders` LIKE 'unzer_payment_id'");
        if (xtc_db_num_rows($result) === 0) {
            xtc_db_query("ALTER TABLE `orders` ADD `unzer_payment_id` VARCHAR(255) NULL DEFAULT NULL");
        }
        $result = xtc_db_query("SHOW COLUMNS FROM `orders` LIKE 'unzer_payment_method'");
        if (xtc_db_num_rows($result) === 0) {
            xtc_db_query("ALTER TABLE `orders` ADD `unzer_payment_method` VARCHAR(255) NULL DEFAULT NULL");
        }
        $result = xtc_db_query("SHOW COLUMNS FROM `orders` LIKE 'unzer_payment_method_label'");
        if (xtc_db_num_rows($result) === 0) {
            xtc_db_query("ALTER TABLE `orders` ADD `unzer_payment_method_label` VARCHAR(255) NULL DEFAULT NULL");
        }
    }

    public function remove()
    {
        xtc_db_query("DELETE FROM `" . TABLE_CONFIGURATION . "` where `configuration_key` in ('" . implode("', '", $this->keys()) . "')");
    }

    public function keys()
    {
        $keys = [
            'MODULE_PAYMENT_UNZER_STATUS',
            'MODULE_PAYMENT_UNZER_SORT_ORDER',
            'MODULE_PAYMENT_UNZER_PUBLIC_KEY',
            'MODULE_PAYMENT_UNZER_PRIVATE_KEY',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_AUTHORIZED',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_CAPTURED',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_CHARGEBACK',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_REFUNDED',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_TRIGGER_REFUND',
            'MODULE_PAYMENT_UNZER_ORDER_STATUS_ID_TRIGGER_CAPTURE',
            'MODULE_PAYMENT_UNZER_ALLOWED',
        ];

        return $keys;
    }

    public function display()
    {
        //admin configuration form
        require_once UnzerConstants::MODULE_PATH_FS . 'Admin/Modules/UnzerConfigurationController.inc.php';
        $controller = new UnzerConfigurationController();
        return [
            'text' =>
                $controller->getPaymentMethodForm().$controller->getWebhookHtml()
        ];
    }

    public function success()
    {
        global $last_order;
        if(empty($last_order)) {
            return null;
        }
        return  (new UnzerOrderHelper())->getPaymentInformationForOrder((int)$last_order);
    }
}