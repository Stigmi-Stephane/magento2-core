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

namespace MultiSafepay\ConnectCore\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Store\Model\Store;
use MultiSafepay\ConnectCore\Factory\SdkFactory;
use Psr\Http\Client\ClientExceptionInterface;

class CancelClient implements ClientInterface
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * CaptureClient constructor.
     *
     * @param SdkFactory $sdkFactory
     */
    public function __construct(
        SdkFactory $sdkFactory
    ) {
        $this->sdkFactory = $sdkFactory;
    }

    /**
     * @param TransferInterface $transferObject
     * @return array|null
     * @throws ClientExceptionInterface
     */
    public function placeRequest(TransferInterface $transferObject): ?array
    {
        $request = $transferObject->getBody();

        if (!isset($request['order_id'], $request['payload'])) {
            return null;
        }

        $responseData = $this->sdkFactory->create($request[Store::STORE_ID] ?? null)->getTransactionManager()
            ->captureReservationCancel($request['order_id'], $request['payload'])->getResponseData();

        return array_merge($responseData, $request);
    }
}
