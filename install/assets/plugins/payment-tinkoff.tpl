//<?php
/**
 * Payment Tinkoff
 *
 * <strong>0.1</strong> Tinkoff payments processing
 *
 * @category    plugin
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @modx_category Commerce
 * @internal    @properties &title=Название;text;Tinkoff (Visa, MasterCard и прочее) &terminal=Терминал;text;; &password=Пароль;text;; &tax_system_code=Код системы налогообложения;list;Общая система налогообложения==osn||Упрощенная (УСН, доходы)==usn_income||Упрощенная (УСН, доходы минус расходы)==usn_income_outcome||Единый налог на вмененный доход (ЕНВД)==envd||Единый сельскохозяйственный налог (ЕСН)==esn||Патентная система налогообложения==patent;1 &vat_code=Код ставки НДС;list;Без НДС==none||НДС по ставке 0%==vat0||НДС по ставке 10%==vat10||НДС чека по ставке 20%==vat20||НДС чека по расчетной ставке 10/110==vat110||НДС чека по расчетной ставке 20/120==vat120;1 &processing_status_id=ID статуса В обработке (order.status.processing);text;2 &canceled_status_id=ID статуса Отменён (order.status.canceled);text;5 &debug=Отладка (подробное логирование);list;Нет==0||Да==1;1
 * @internal    @disabled 0
 * @internal    @installset base
 */
if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'tinkoff';
$commerce = ci()->commerce;
$lang = $commerce->getUserLanguage('tinkoff');

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\TinkoffPayment($modx, $params);

        if (empty($params['title'])) {
            $params['title'] = $lang['tinkoff.caption'];
        }

        $commerce->registerPayment('tinkoff', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isSelectedPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $commerce->loadProcessor()->populateOrderPaymentLink());
        }

        break;
    }

    case 'OnManagerBeforeOrderRender': {
        if (isset($params['groups']['payment_delivery']) && $isSelectedPayment) {
            $params['groups']['payment_delivery']['fields']['payment_link'] = [
                'title'   => $lang['tinkoff.link_caption'],
                'content' => function($data) use ($commerce) {
                    return $commerce->loadProcessor()->populateOrderPaymentLink('@CODE:<a href="[+link+]" target="_blank">[+link+]</a>');
                },
                'sort' => 50,
            ];
        }

        break;
    }
}

