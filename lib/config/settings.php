<?php
return array(
    'payout_address'   => array(
        'value'        => '',
        'title'        => 'Адрес для вывода',
        'description'  => 'Номер кошелька',
        'control_type' => waHtmlControl::INPUT,
    ),
    'master_key'   => array(
        'value'        => '',
        'title'        => 'Мастер ключ (SEED)',
        'description'  => '128bit последовательность',
        'control_type' => waHtmlControl::INPUT,
    ),
    'invoice_prefix'   => array(
        'value'        => '',
        'title'        => 'Префикс пути',
        'description'  => "<b style='color:red;'>44</b>'/0/0/N",
        'control_type' => waHtmlControl::INPUT,
    ),
    /*
    'confirmations'    => array(
        'value'        => '3',
        'title'        => 'Количество подтверждений',
        'description'  => 'Число принятых подтверждений платежа в сети PKOIN. <b>Не рекомендуется устанавливать ниже 3</b>',
        'control_type' => waHtmlControl::INPUT,
    ),
    'fee_level'        => array(
        'value'        => kmbitcoinPayment::FEE_LOW,
        'title'        => 'Уровень комиссии сети',
        'description'  => 'Чем выше комиссия, тем быстрее проходят платежи.',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            kmbitcoinPayment::FEE_LOW    => 'низкий',
            kmbitcoinPayment::FEE_MEDIUM => 'средний',
            kmbitcoinPayment::FEE_HIGH   => 'высокий',
        ),
    ),
    */
    'show_qr'          => array(
        'value'        => true,
        'title'        => 'Показывать QR код',
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    /*
    'before' => array(
        'value'        => 'Оплата PKOIN',
        'title'        => 'Текст перед данными для оплаты',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Текст ДО данных платежа.',
    ),
    'after'  => array(
        'value'        => 'Сумма сконвертирована по курсу на текущий момент.',
        'title'        => 'Текст после данных для оплаты',
        'control_type' => waHtmlControl::INPUT,
        'description'  => 'Текст ПОСЛЕ данных платежа.',
    ),
    */
    'fee_type'  => array(
        'value'        => pocketcoinPayment::FEE_MINUS,
        'title'        => 'Откуда брать из комиссию сервиса',
        'description'  => 'Выберите брать комиссию сервиса из суммы заказа или добавлять к сумме заказа.',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            pocketcoinPayment::FEE_MINUS   => 'брать из суммы заказа',
            pocketcoinPayment::FEE_PLUS => 'добавлять к сумме заказа',
        ),
    ),
    'info'             => array(
        'value'        => '',
        'description'  => 'Используется API сервиса pocketnet.app. Комиссия сервиса и разработчика составляет 0.001 PKOIN с каждой обработанной транзакции. <!--При сумме меньше 0.001 комиссия не взимается. Минимальная сумма для процессинга платежей 0.0005 BTC.-->',
        'control_type' => waHtmlControl::HELP,
    ),
);
