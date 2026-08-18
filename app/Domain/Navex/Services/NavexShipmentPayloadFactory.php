<?php

namespace App\Domain\Navex\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\OrderDesignationFormatter;
use App\Domain\Commerce\Services\OrderExchangeDetails;
use App\Domain\Navex\Models\NavexConfiguration;
use Normalizer;

class NavexShipmentPayloadFactory
{
    public function __construct(
        private readonly OrderDesignationFormatter $designationFormatter,
        private readonly OrderExchangeDetails $exchangeDetails,
    ) {}

    /** @return array<string, string|int> */
    public function make(Order $order, NavexConfiguration $configuration): array
    {
        $totalQuantity = (int) $order->items->sum('quantity');
        $isExchange = (bool) $order->is_exchange;
        $exchangeDesignation = (string) $order->exchange_article_designation;
        $exchangeCount = $order->exchange_article_count;
        if (! $isExchange && $order->relationLoaded('checkoutValues')) {
            $legacyExchange = $this->exchangeDetails->fromCheckoutCustomer($order->checkoutValues->pluck('value', 'field_key_snapshot')->all());
            $isExchange = $legacyExchange['is_exchange'];
            $exchangeDesignation = (string) $legacyExchange['exchange_article_designation'];
            $exchangeCount = $legacyExchange['exchange_article_count'];
        }

        return [
            'prix' => number_format($order->total_millimes / 1000, 3, '.', ''),
            // Navex only receives a provider-safe copy of free text. The
            // committed order/configuration values are never modified.
            'nom' => $this->navexFreeText((string) $order->customer_name, 'Client'),
            'gouvernerat' => $this->navexFreeText((string) $order->customer_governorate),
            'ville' => $this->navexFreeText((string) $order->customer_city),
            'adresse' => $this->navexFreeText((string) $order->customer_address),
            'tel' => $order->customer_phone,
            'tel2' => '',
            'designation' => $this->navexFreeText($this->designation($order)),
            'nb_article' => $totalQuantity,
            'msg' => 'fraagiiiiiiiiiiilleee',
            // Navex's own pickup form serializes this flag as 1 (Oui) or 0
            // (Non). Keep the French labels in our domain/UI, but use the
            // provider's wire representation so the exchange radio is stored
            // correctly by Navex.
            'echange' => $isExchange ? '1' : '0',
            'article' => $isExchange ? $this->navexFreeText($exchangeDesignation) : '',
            'nb_echange' => $isExchange ? (string) $exchangeCount : '',
            'ouvrir' => 'Oui',
            'sender_name' => $this->navexFreeText((string) $configuration->sender_name),
            'sender_location' => $this->navexFreeText((string) $configuration->sender_location),
            'sender_gouvernorat' => $this->navexFreeText((string) $configuration->sender_governorate),
        ];
    }

    public function designation(Order $order): string
    {
        return $this->designationFormatter->format($order);
    }

    private function navexFreeText(string $value, string $fallback = ''): string
    {
        $compatibilityNormalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $value = is_string($compatibilityNormalized) ? $compatibilityNormalized : $value;
        $withoutInvisibleFormatting = preg_replace(
            '/[\p{Cf}\x{FE0E}\x{FE0F}\x{20E3}]/u',
            '',
            $value,
        );
        $withoutPictographs = preg_replace(
            '/[\p{Extended_Pictographic}\p{Emoji_Presentation}]/u',
            ' ',
            $withoutInvisibleFormatting ?? $value,
        );
        $normalized = preg_replace('/\s+/u', ' ', trim($withoutPictographs ?? $value));

        return $normalized !== null && $normalized !== '' ? $normalized : $fallback;
    }
}
