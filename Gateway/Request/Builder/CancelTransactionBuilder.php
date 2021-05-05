<?php
/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is provided with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

declare(strict_types=1);

namespace MultiSafepay\ConnectCore\Gateway\Request\Builder;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\SalesSequence\Model\Manager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Exception\CouldNotInvoiceException;
use Magento\Store\Model\Store;
use MultiSafepay\Api\Transactions\CaptureRequest;
use MultiSafepay\Api\Transactions\Transaction;
use MultiSafepay\ConnectCore\Factory\SdkFactory;
use MultiSafepay\ConnectCore\Util\AmountUtil;
use MultiSafepay\ConnectCore\Util\CaptureUtil;
use MultiSafepay\ConnectCore\Util\ShipmentUtil;
use Psr\Http\Client\ClientExceptionInterface;

class CancelTransactionBuilder implements BuilderInterface
{
    /**
     * @var AmountUtil
     */
    private $amountUtil;

    /**
     * @var CaptureUtil
     */
    private $captureUtil;

    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var CaptureRequest
     */
    private $captureRequest;

    /**
     * @var ShipmentUtil
     */
    private $shipmentUtil;

    /**
     * @var Manager
     */
    private $sequenceManager;

    /**
     * CaptureTransactionBuilder constructor.
     *
     * @param AmountUtil $amountUtil
     * @param CaptureUtil $captureUtil
     * @param SdkFactory $sdkFactory
     * @param CaptureRequest $captureRequest
     * @param ShipmentUtil $shipmentUtil
     * @param Manager $sequenceManager
     */
    public function __construct(
        AmountUtil $amountUtil,
        CaptureUtil $captureUtil,
        SdkFactory $sdkFactory,
        CaptureRequest $captureRequest,
        ShipmentUtil $shipmentUtil,
        Manager $sequenceManager
    ) {
        $this->amountUtil = $amountUtil;
        $this->captureUtil = $captureUtil;
        $this->sdkFactory = $sdkFactory;
        $this->captureRequest = $captureRequest;
        $this->shipmentUtil = $shipmentUtil;
        $this->sequenceManager = $sequenceManager;
    }

    /**
     * @param array $buildSubject
     * @return array
     * @throws ClientExceptionInterface
     * @throws CouldNotInvoiceException
     */
    public function build(array $buildSubject): array
    {
        $paymentDataObject = SubjectReader::readPayment($buildSubject);
        $amount = (float)SubjectReader::readAmount($buildSubject);

        if ($amount <= 0) {
            throw new CouldNotInvoiceException(
                __('Invoices with 0 amount can not be processed. Please set a different amount')
            );
        }

        $payment = $paymentDataObject->getPayment();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();
        $orderIncrementId = $order->getIncrementId();
        $storeId = (int)$order->getStoreId();
        $transactionManager = $this->sdkFactory->create($storeId)->getTransactionManager();
        $transaction = $transactionManager->get($orderIncrementId);

        if (!$this->captureUtil->isManualCapturePossibleForAmount($transaction, round($amount * 100, 10))
        ) {
            throw new CouldNotInvoiceException(__('Payment capture amount is not valid, please try again.'));
        }

        $captureRequest = $this->captureRequest->addData(
            $this->prepareCaptureRequestData($amount, $order, $payment)
        );

        return [
            'payload' => $captureRequest,
            'order_id' => $orderIncrementId,
            Store::STORE_ID => $storeId,
        ];
    }

    /**
     * @param float $amount
     * @param OrderInterface $order
     * @param InfoInterface $payment
     * @return array
     */
    private function prepareCaptureRequestData(float $amount, OrderInterface $order, InfoInterface $payment): array
    {
        $invoice = $payment->getInvoice() ?: $order->getInvoiceCollection()->getLastItem();

        if ($invoice && !$invoice->getIncrementId()) {
            $invoice->setIncrementId(
                $this->sequenceManager->getSequence(
                    $invoice->getEntityType(),
                    $order->getStoreId()
                )->getNextValue()
            );
        }

        $shipment = $payment->getShipment();
        $result = [
            "amount" => round($this->amountUtil->getAmount($amount, $order) * 100, 10),
            "invoice_id" => $invoice ? $invoice->getIncrementId() : "",
            "new_order_status" => $shipment ? Transaction::SHIPPED : Transaction::COMPLETED,
        ];

        return $shipment ? array_merge($result, $this->shipmentUtil->getShipmentApiRequestData($order, $shipment))
            : $result;
    }
}
