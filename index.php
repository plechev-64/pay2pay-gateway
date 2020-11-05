<?php

if(class_exists('Rcl_Payment')){

add_action('init','rcl_add_paytopay_payment');
function rcl_add_paytopay_payment(){
    $pm = new Rcl_Paytopay_Payment();
    $pm->register_payment('pay2pay');
}

class Rcl_Paytopay_Payment extends Rcl_Payment{

    public $form_pay_id;

    function register_payment($form_pay_id){
        $this->form_pay_id = $form_pay_id;
        parent::add_payment($this->form_pay_id, array(
            'class'=>get_class($this),
            'request'=>'xml',
            'name'=>'Pay2Pay',
            'image'=>rcl_addon_url('assets/pay2pay.jpg',__FILE__)
            ));
        if(is_admin()) $this->add_options();
    }

    function add_options(){
        add_filter('rcl_pay_option',(array($this,'options')));
        add_filter('rcl_pay_child_option',(array($this,'child_options')));
    }

    function options($options){
        $options[$this->form_pay_id] = 'Pay2Pay';
        return $options;
    }

    function child_options($child){
        global $rmag_options;

        $opt = new Rcl_Options();

        $curs = array( 'RUB', 'USD', 'EUR' );

        if(false !== array_search($rmag_options['primary_cur'], $curs)) {
            $options = array(
                array(
                    'type' => 'text',
                    'slug' => 'p2p_merchant',
                    'title' => __('Идентификатор магазина')
                ),
                array(
                    'type' => 'text',
                    'slug' => 'p2p_secret',
                    'title' => __('Секретный ключ')
                ),
                array(
                    'type' => 'text',
                    'slug' => 'p2p_hidden',
                    'title' => __('Скрытый ключ')
                ),
                array(
                    'type' => 'select',
                    'slug' => 'p2p_status',
                    'title' => __('Режим работы'),
                    'values'=>array(
                        'Рабочий',
                        'Тестовый'
                    )
                )
            );
        }else{
            $options = array(
                array(
                    'type' => 'custom',
                    'slug' => 'notice',
                    'notice' => __('<span style="color:red">Данное подключение не поддерживает действующую валюту сайта.<br>'
                        . 'Поддерживается работа с RUB, USD, EUR</span>')
                )
            );
        }

        $child .= $opt->child(
            array(
                'name'=>'connect_sale',
                'value'=>$this->form_pay_id
            ),
            array(
                $opt->options_box( __('Настройки подключения Pay2Pay'), $options)
            )
        );

        return $child;
    }

    function pay_form($data){
        global $rmag_options;

        $desc = ($data->description)? $data->description: 'Платеж от '.get_the_author_meta('user_email',$data->user_id);

        // Инициализация параметров платежа
        $merchant_id = $rmag_options['p2p_merchant']; // Идентификатор магазина в Pay2Pay
        $secret_key = $rmag_options['p2p_secret']; // Секретный ключ
        $order_id = $data->pay_id; // Номер заказа
        $amount = number_format($data->pay_summ, 2, '.', ''); // Сумма заказа
        $currency = $rmag_options['primary_cur']; // Валюта заказа
        $test_mode = $rmag_options['p2p_status']; // Тестовый режим

        $baggage_data = ($data->baggage_data)? $data->baggage_data: false;

        $other = json_encode(array(
                    'user_id' => $data->user_id,
                    'pay_type' => $data->pay_type,
                    'baggage_data' => $baggage_data
                ));

        // Формирование xml
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
         <request>
         <version>1.2</version>
         <merchant_id>$merchant_id</merchant_id>
         <language>ru</language>
         <order_id>$order_id</order_id>
         <amount>$amount</amount>
         <currency>$currency</currency>
         <description>$desc</description>
         <test_mode>$test_mode</test_mode>
         <other>$other</other>
         </request>";

        // Вычисление подписи
        $sign = md5($secret_key.$xml.$secret_key);

        // Кодирование данных в BASE64
        $xml_encode = base64_encode($xml);
        $sign_encode = base64_encode($sign);

        $fields = array(
            'xml'=>$xml_encode,
            'sign'=>$sign_encode
        );

        $form = parent::form($fields,$data,'https://merchant.pay2pay.com/?page=init');

        return $form;
    }

    function result($data){
        global $rmag_options;

        if (!isset($_REQUEST['xml']) || !isset($_REQUEST['sign'])) return false;

        $hidden_key = $rmag_options['p2p_hidden'];
        $xml_encoded = $_REQUEST["xml"];
        $sign = $_REQUEST["sign"];

        $xml_encoded = str_replace(' ', '+', $xml_encoded);
        $xml = base64_decode($xml_encoded);

        $my_sign = md5($hidden_key.$xml.$hidden_key);
        $my_sign_encode = base64_encode($my_sign);

        $xml = simplexml_load_string($xml);

        $details = json_decode($xml->other);

        $data->user_id = $details->user_id;
        $data->pay_type = $details->pay_type;
        $data->baggage_data = $xml->baggage_data;
        $data->pay_summ = $xml->amount;
        $data->pay_id = $xml->order_id;

        if($my_sign_encode==$sign){
            if(!parent::get_pay($data)&&$xml->status=='success'){
                parent::insert_pay($data);
            }
            $this->output_xml(array('result'=>'yes')); exit;
        }else{
            $this->output_xml(array('result'=>'no','error_msg'=>'Неверная подпись')); exit;
        }
    }

    function output_xml($args){
        $output=new DomDocument('1.0','utf-8');
        $result = $output->appendChild($output->createElement('result'));

        $status = $result->appendChild($output->createElement('status'));
        $status->appendChild($output->createTextNode($args['status']));

        if(isset($args['error_msg'])){
            $error_msg = $result->appendChild($output->createElement('error_msg'));
            if($args['error_msg'])
                $error_msg->appendChild($output->createTextNode($args['error_msg']));
        }

        $output->formatOutput = true;
        echo $output;
    }

    function success(){
        global $rmag_options;

        if (!isset($_REQUEST['xml']) || !isset($_REQUEST['sign'])) return false;

        $xml = base64_decode(str_replace(' ', '+', $_REQUEST['xml']));
        $sign = base64_decode(str_replace(' ', '+', $_REQUEST['sign']));

        // преобразование входного xml в удобный для использования формат
        $vars = simplexml_load_string($xml);

        $details = json_decode($xml->other);

        $pay_data = array(
            'pay_id'=>$vars->order_id,
            'user_id'=>$details->user_id
        );

        if(parent::get_pay((object)$pay_data)){
            wp_redirect(get_permalink($rmag_options['page_successfully_pay'])); exit;
        } else {
            wp_die('Платеж не найден в базе данных');
        }

    }

}

}