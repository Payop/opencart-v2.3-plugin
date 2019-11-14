<?php

/**
 * Class ControllerExtensionPaymentPayop
 */
class ControllerExtensionPaymentPayop extends Controller
{
    /**
     * @return mixed
     */
    public function index()
    {
        $this->load->model('checkout/order');
        $this->load->language('extension/payment/payop');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $orderData = [];
        $orderData['publicKey'] = $this->config->get('payop_public');
        $orderData['order'] = [];
        $orderData['order']['id'] = $this->session->data['order_id'];
        $orderData['order']['amount'] = number_format($this->currency->format($order_info['total'], $order_info['currency_code'], '', false), 4);
        $orderData['order']['currency'] = $order_info['currency_code'];
        $orderData['order']['description'] = 'Payment order #' . $this->session->data['order_id'];
        $orderData['customer'] = [];
        $orderData['customer']['email'] = $order_info['email'];
        $orderData['customer']['name'] = $order_info['payment_firstname'] . ' ' . $order_info['payment_lastname'];
        $orderData['customer']['phone'] = $order_info['telephone'];
        $orderData['language'] = ($this->session->data['language'] == 'ru_ru') ? 'ru' : 'en';
        $orderData['resultUrl'] = $this->url->link('checkout/success');
        $orderData['failUrl'] = $this->url->link('checkout/failure');

        /** generate signature */
        $orderSet = ['id' => $this->session->data['order_id'], 'amount' => number_format($this->currency->format($order_info['total'], $order_info['currency_code'], '', false), 4), 'currency' => $order_info['currency_code']];
        ksort($orderSet, SORT_STRING);
        $dataSet = array_values($orderSet);
        array_push($dataSet, $this->config->get('payop_secret'));
        $orderData['signature'] = hash('sha256', implode(':', $dataSet));

        $payUrl = $this->apiRequest($orderData);
        $data['action'] = $payUrl['data']['redirectUrl'];
        return $this->load->view('extension/payment/payop', $data);

    }

    /**
     * @param $orderData array
     * @return mixed
     */
    private function apiRequest($orderData)
    {
        $apiUrl = 'https://PayOp.com/api/v1.1/payments/payment';
        $data = json_encode($orderData);
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true);
    }

    /**
     * get transaction info and update order status
     */
    public function callback()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $callback = json_decode(file_get_contents('php://input'));
            if (is_object($callback)) {
                $sign_string = $callback->amount . ':' . $callback->currency . ':' . $callback->orderId . ':' . $callback->status . ':' . $this->config->get('payop_secret');
                if ($callback->signature == hash('sha256', $sign_string)) {
                    $this->load->model('checkout/order');
                    if ($callback->status === 'success')
                        $this->model_checkout_order->addOrderHistory($callback->orderId, $this->config->get('payop_order_status_id'));
                    else if ($callback->status === 'error')
                        $this->model_checkout_order->addOrderHistory($callback->orderId, $this->config->get('payop_failed_status_id'));
                } else {
                    $this->log->write('Error callback!');
                }
            }
        }
    }
}
