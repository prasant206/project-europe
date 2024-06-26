<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace PayPal\Braintree\Block\Customer\ApplePay;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template\Context;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractTokenRenderer;
use Magento\Vault\Block\CardRendererInterface;
use PayPal\Braintree\Model\ApplePay\Ui\ConfigProvider;

/**
 * @api
 * @since 100.0.2
 */
class VaultTokenRenderer extends AbstractTokenRenderer implements CardRendererInterface
{
    /**
     * @var ConfigProvider
     */
    private ConfigProvider $configProvider;

    /**
     * @param Context $context
     * @param ConfigProvider $configProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConfigProvider $configProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->configProvider = $configProvider;
    }

    /**
     * Can render specified token
     *
     * @param PaymentTokenInterface $token
     * @return boolean
     */
    public function canRender(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === ConfigProvider::METHOD_CODE;
    }

    /**
     * Get Number Last 4 Digits
     *
     * @return string
     */
    public function getNumberLast4Digits(): string
    {
        return $this->getTokenDetails()['maskedCC'] ?? '';
    }

    /**
     * Get exp Date
     *
     * @return string
     */
    public function getExpDate(): string
    {
        return $this->getTokenDetails()['expirationDate'] ?? '';
    }

    /**
     * Get Icon Url
     *
     * @return string
     * @throws LocalizedException
     */
    public function getIconUrl(): string
    {
        return $this->getIconForType($this->getTokenDetails()['type'] ?? '')['url'];
    }

    /**
     * Get Icon Height
     *
     * @return int
     * @throws LocalizedException
     */
    public function getIconHeight(): int
    {
        return $this->getIconForType($this->getTokenDetails()['type'] ?? '')['height'];
    }

    /**
     * Get Icon Width
     *
     * @return int
     * @throws LocalizedException
     */
    public function getIconWidth(): int
    {
        return $this->getIconForType($this->getTokenDetails()['type'] ?? '')['width'];
    }

    /**
     * Get Icon type
     *
     * @param string $type
     * @return array
     * @throws LocalizedException
     * @since 100.1.0
     */
    private function getIconForType(string $type): array
    {
        return $this->configProvider->getIcons()[strtolower($type)] ?? [
            'url' => '',
            'width' => 0,
            'height' => 0
        ];
    }
}
