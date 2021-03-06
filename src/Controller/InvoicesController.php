<?php

namespace App\Controller;

use App\Controller\AppController;
use Cake\Event\Event;

class InvoicesController extends AppController {

    public function initialize() {
        parent::initialize();

        // Load Models        
        $this->loadModel('Payments');
        $this->loadModel('PaymentGatewayResponses');

        // Load Components
        $this->loadComponent('Custom');
        $this->loadComponent('Paginator');

        // Set Layout
        $this->viewBuilder()->setLayout('');
    }

    public function beforeFilter(Event $event) {
        parent::beforeFilter($event);
        $this->Auth->allow();
    }

    /*
     * Developer   :  Pradeepta Kumar Khatoi
     * Date        :  13th Dec 2018
     * Description :  Preview Invoice Public Page.
     */

    public function previewInvoice($uniqID = NULL) {
        $this->viewBuilder()->setLayout('');

        $query = $this->Payments->find()->where(['Payments.uniq_id' => $uniqID])->contain(['Webfronts.Users.MerchantPaymentGateways.PaymentGateways', 'Webfronts.Merchants', 'UploadedPaymentFiles']);

        if ($query->count() == 0) {
            throw new \Cake\Http\Exception\NotFoundException("Invoice Not Found!!");
        }

        $payment = $query->first();

        $payment->late_fee_amount = $payment->status == 1 ? $payment->late_fee_amount : $this->Custom->calLateFee($payment);
        $advance = empty($payment->reference_number) ? true : false;

        $this->set(compact('payment', 'advance'));

        $this->loadComponent('Mpdf');
        // Generate PDF Start        
        if (isset($_REQUEST['pdf']) && $_REQUEST['pdf'] == 'true') {
            $this->Mpdf->init(); // Initializing mPDF               
            $this->Mpdf->setFilename(INVOICE_PDF . "/" . sprintf("%04d", $payment->id) . '.pdf'); // Setting filename of output pdf file
            $this->Mpdf->setOutput('F'); // Setting output to I, D, F, S                
            $this->Mpdf->SetWatermarkText("Draft");
        }

        // Generate & Download PDF 
        if (isset($_REQUEST['download'])) {
            $this->Mpdf->init();
            $this->Mpdf->setFilename(INVOICE_PDF . "/" . sprintf("%04d", $payment->id) . '.pdf');
            $this->Mpdf->setOutput('DF');
            $this->Mpdf->SetWatermarkText("Draft");
        }
    }

    public function payuResponse($uniqID = NULL) {
        if ($this->request->is('post')) {

            // Save Payment Gateway infromation to log table for future use.
            $this->PaymentGatewayResponses->saveToLog($_POST, $uniqID);

            $payment = $this->Payments->find()->where(['Payments.uniq_id' => $uniqID])->contain(['Webfronts.Users.MerchantPaymentGateways.PaymentGateways', 'Webfronts.Merchants', 'UploadedPaymentFiles'])->first();

            $status = $_POST["status"];
            $firstname = $_POST["firstname"];
            $amount = $_POST["amount"];
            $txnid = $_POST["txnid"];
            $key = $_POST["key"];
            $productinfo = $_POST["productinfo"];
            $email = $_POST["email"];
            $mode = $_POST['mode'];
            $udf1 = $_POST['udf1'];
            $udf2 = $_POST['udf2'];

            $key = $payment->webfront->user->merchant_payment_gateway->merchant_key;
            $salt = $payment->webfront->user->merchant_payment_gateway->merchant_salt;

            $postedHash = $_POST["hash"];

            $additionalCharges = 0;
            if (isset($_POST["additionalCharges"])) {
                $additionalCharges = $_POST["additionalCharges"];
                $retHashSeq = "{$additionalCharges}|{$salt}|{$status}|||||||||$udf2|$udf1|{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
            } else {
                $retHashSeq = "{$salt}|{$status}|||||||||$udf2|$udf1|{$email}|{$firstname}|{$productinfo}|{$amount}|{$txnid}|{$key}";
            }

            $hash = hash("sha512", $retHashSeq);

            if ($hash == $postedHash) {

                // Update Payments Table 
                $status = ($_POST['unmappedstatus'] == 'captured') ? 1 : 0;
                $paidAmount = $amount + $additionalCharges;
                $fileds = [
                    'status' => $status,
                    'unmappedstatus' => $_POST['unmappedstatus'],
                    'txn_id' => $txnid,
                    'payment_date' => date('Y-m-d'),
                    'mode' => $mode,
                    'paid_amount' => $paidAmount,
                    'convenience_fee_amount' => $_POST["udf1"],
                    'late_fee_amount' => $_POST["udf2"],
                    'paid_via' => 'PayuMoney',
                ];
                //$this->Payments->query()->update()->set($fileds)->where(['uniq_id' => $uniqID])->execute();
                $this->Payments->patchEntity($payment, $fileds, ['associated' => []]);
                $this->Payments->save($payment);

                // Generate Invoice PDF
                $this->Payments->geterateInvoicePdf($uniqID);

                // Send SMS to Customer
                if (!empty($payment->phone) && strlen($payment->phone) == 10) {
                    $sms = "You have successfully paid Rs. {$payment->paid_amount} for the webfront {$payment->webfront->title} of the merchant {$payment->webfront->user->name} using PayuMoney.Your Transaction No. is {$payment->txn_id}.";
                    sendSMS($payment->phone, $sms);
                }

                // Send Email To Customer
                $this->Payments->sendEmail($payment->id, 'PAYMENT_CONFIRMATION');

                $this->Flash->success(__('Payment Made Successfully!!'));
            } else {
                $this->Flash->success(__('Payment Failed!!'));
            }
        }
        return $this->redirect(HTTP_ROOT . 'preview-invoice/' . $uniqID);
    }

    public function razorPayResponse($uniqID) {

        if ($this->request->is('post')) {

            $data = $this->request->getData();

            $payment = $this->Payments->find()->where(['Payments.uniq_id' => $data['uniq_id']])->contain(['Webfronts.Users.MerchantPaymentGateways.PaymentGateways','Webfronts.Users'])->first();
            $key = $payment->webfront->user->merchant_payment_gateway->merchant_key;
            $salt = $payment->webfront->user->merchant_payment_gateway->merchant_salt;
            $paymentResponse = $this->razorPaymentStatus($data['razorpay_payment_id'],$key,$salt);

            if ($paymentResponse->status == 'authorized') {
                $paymentCaptureResponse = $this->razorPaymentCapture($paymentResponse->id,$paymentResponse->amount,$key,$salt);
                $data['unmappedstatus'] = $paymentCaptureResponse->status;
                if ($paymentCaptureResponse->method = 'card') {
                    if ($paymentCaptureResponse->card->type = 'debit') {
                        $data['mode'] = "DC";
                    }elseif($paymentCaptureResponse->card->type = 'credit'){
                        $data['mode'] = "CC";
                    }else {
                        $data['mode'] = $paymentCaptureResponse->method;
                    }
                }
                else{
                    $data['mode'] = $paymentCaptureResponse->method;
                }
            }
            else
            {
                $data['unmappedstatus'] = $paymentResponse->status;
                $data['mode'] = $paymentResponse->method;
            }            
            // Save Payment Gateway infromation to log table for future use.
            $this->PaymentGatewayResponses->saveToLog($data, $uniqID);

            $data['txn_id'] = $data['razorpay_payment_id'];
            $data['status'] = 1;
            $data['payment_date'] = date('Y-m-d');
            $data['paid_via'] = 'RazorPay';

            $this->Payments->patchEntity($payment, $data, ['associated' => []]);

            if ($this->Payments->save($payment)) {

                // Generate Invoice PDF
                $this->Payments->geterateInvoicePdf($uniqID);

                // Send SMS to Customer
                if (!empty($payment->phone) && strlen($payment->phone) == 10) {
                    $sms = "You have successfully paid Rs. {$payment->paid_amount} for the webfront {$payment->webfront->title} of the merchant {$payment->webfront->user->name} using RazorPay.Your Transaction No. is {$payment->txn_id}.";
                    sendSMS($payment->phone, $sms);
                }

                // Send Email To Customer
                $this->Payments->sendEmail($payment->id, 'PAYMENT_CONFIRMATION');

                $this->Flash->success(__('Payment Made Successfully!!'));
            } else {
                $this->Flash->success(__('Payment Failed!!'));
            }
        }
        return $this->redirect(HTTP_ROOT . 'preview-invoice/' . $uniqID);
    }
    protected function razorPaymentStatus($paymentId,$key,$salt){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, RAZORPAY_API_URL.$paymentId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $salt);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            return 'Error:' . curl_error($ch);
        }
        curl_close ($ch);
        return json_decode($result);
    }
    protected function razorPaymentCapture($paymentId,$amount,$key,$salt){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, RAZORPAY_API_URL.$paymentId.'/capture');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "amount=".$amount);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $key . ':' . $salt);

        $headers = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close ($ch);
        return json_decode($result);
    }
}
