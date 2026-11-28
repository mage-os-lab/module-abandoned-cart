<?php
declare(strict_types=1);

namespace MageOS\AbandonedCart\Model;

use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;

/**
 * Builds and sends the e-mail of a reminder, for a cart or as a test with sample products.
 */
class ReminderMail
{
    private const CONFIG = 'mageos_abandoned_cart/';
    private const SAMPLE_PRODUCTS = 3;

    /** Code shown in a test e-mail whose reminder has a coupon: a test generates no real coupon. */
    public const SAMPLE_COUPON = 'TEST-COUPON';

    /**
     * @param TransportBuilder $transportBuilder
     * @param ScopeConfigInterface $config
     * @param Emulation $emulation
     * @param CollectionFactory $products
     * @param Visibility $visibility
     * @param Image $image
     * @param PriceCurrencyInterface $priceCurrency
     * @param Escaper $escaper
     * @param Url $url storefront URLs, also when the test is sent from the admin
     * @param ReminderLink $reminderLink
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $config,
        private readonly Emulation $emulation,
        private readonly CollectionFactory $products,
        private readonly Visibility $visibility,
        private readonly Image $image,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Escaper $escaper,
        private readonly Url $url,
        private readonly ReminderLink $reminderLink
    ) {
    }

    /**
     * Send the e-mail of reminder $step, with the template of the store view
     *
     * @param int $storeId
     * @param int $step
     * @param string $to
     * @param array<string,mixed> $vars customer_name, items, subtotal, cart_url, unsubscribe_url and coupon_code
     * @param bool $oneClick false for a test, whose stop link stops nothing
     */
    public function send(int $storeId, int $step, string $to, array $vars, bool $oneClick = true): void
    {
        $transport = $this->transportBuilder
            ->setTemplateIdentifier(
                $this->config->getValue(self::CONFIG . "reminder$step/template", ScopeInterface::SCOPE_STORE, $storeId)
            )
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars($vars)
            ->setFromByScope('sales', $storeId)
            ->addTo($to)
            ->getTransport();
        $message = $transport->getMessage();
        // RFC 8058 one-click unsubscribe, which Gmail and Yahoo expect from bulk senders.
        if ($oneClick && $message instanceof EmailMessage) {
            $message->getSymfonyMessage()->getHeaders()
                ->addTextHeader('List-Unsubscribe', '<' . $vars['unsubscribe_url'] . '>')
                ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $transport->sendMessage();
    }

    /**
     * Line of the e-mail for a product, escaped because {{var}} inside {{for}} is output raw by ForDirective
     *
     * Runs under the emulation of the store, for its image sizes and media URL.
     *
     * @param Product $product
     * @param string $name
     * @param float $qty
     * @param string $price
     * @param float $amount
     * @return array{name: string, qty: float, price: string, image_url: string, amount: float}
     */
    public function item(Product $product, string $name, float $qty, string $price, float $amount): array
    {
        return [
            'name' => $this->escaper->escapeHtml($name),
            'qty' => $qty,
            'price' => $this->escaper->escapeHtml($price),
            'image_url' => $this->escaper->escapeUrl(
                $this->image->init($product, 'cart_page_product_thumbnail')->getUrl()
            ),
            'amount' => $amount,
        ];
    }

    /**
     * Send reminder $step of the store view to $to, with a few products of the store as the cart
     *
     * Its links open the cart page: there is no cart to restore and nothing to stop. A reminder with a coupon shows
     * SAMPLE_COUPON, which no rule accepts.
     *
     * shortcut: the sample lists simple products at their catalog price, without price rules or tax; take the
     * products of a real cart if the test must match a reminder to the cent.
     *
     * @param int $storeId
     * @param int $step
     * @param string $to
     * @throws LocalizedException
     */
    public function sendTest(int $storeId, int $step, string $to): void
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $products = $this->products->create()
                ->addStoreFilter($storeId)
                ->addAttributeToSelect(['name', 'price', 'thumbnail'])
                ->addAttributeToFilter('type_id', ['eq' => Type::TYPE_SIMPLE])
                ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
                ->addAttributeToFilter('visibility', ['in' => $this->visibility->getVisibleInSiteIds()])
                ->addAttributeToFilter('price', ['gt' => 0])
                ->setPageSize(self::SAMPLE_PRODUCTS);
            $items = [];
            /** @var Product $product */
            foreach ($products as $product) {
                $amount = (float) $this->priceCurrency->convert((float) $product->getPrice(), $storeId);
                $price = $this->price($amount, $storeId);
                $items[] = $this->item($product, (string) $product->getName(), 1.0, $price, $amount);
            }
            if (!$items) {
                throw new LocalizedException(
                    __('The test e-mail needs an enabled simple product with a price in this store view.')
                );
            }
            $utm = $this->config->isSetFlag(self::CONFIG . 'general/utm', ScopeInterface::SCOPE_STORE, $storeId);
            $cartUrl = $this->url->getUrl('checkout/cart', [
                '_scope' => $storeId,
                '_nosid' => true,
                '_query' => $utm ? $this->reminderLink->utm($step) : [],
            ]);
            $this->send($storeId, $step, $to, [
                'customer_name' => '',
                'items' => $items,
                'subtotal' => $this->price((float) array_sum(array_column($items, 'amount')), $storeId),
                'cart_url' => $cartUrl,
                'unsubscribe_url' => $cartUrl,
                'coupon_code' => $this->config->getValue(
                    self::CONFIG . "reminder$step/coupon_rule",
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ) ? self::SAMPLE_COUPON : '',
            ], false);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Amount in the currency of the store view, as text
     *
     * @param float $amount
     * @param int $storeId
     * @return string
     */
    private function price(float $amount, int $storeId): string
    {
        return $this->priceCurrency->format($amount, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId);
    }
}
