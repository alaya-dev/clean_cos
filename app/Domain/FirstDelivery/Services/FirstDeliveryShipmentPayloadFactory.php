<?php

namespace App\Domain\FirstDelivery\Services;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Services\CustomerPhoneNormalizer;
use App\Domain\Commerce\Services\OrderDesignationFormatter;
use App\Domain\FirstDelivery\Models\FirstDeliveryLocality;
use Normalizer;

class FirstDeliveryShipmentPayloadFactory
{
    public function __construct(
        private readonly OrderDesignationFormatter $designationFormatter,
        private readonly CustomerPhoneNormalizer $phones,
    ) {}

    /** @return array<string, mixed> */
    public function make(Order $order, FirstDeliveryLocality $locality): array
    {
        $totalQuantity = (int) $order->items->sum('quantity');
        $article = $order->items
            ->pluck('product_name_snapshot')
            ->filter()
            ->unique()
            ->implode(' / ');

        return [
            'Client' => [
                'nom' => $this->providerText((string) $order->customer_name, 'Client'),
                'locality_id' => $locality->locality_id,
                'gouvernerat' => $this->providerText((string) $order->customer_governorate),
                'ville' => $this->providerText((string) $order->customer_city),
                'adresse' => $this->providerText((string) $order->customer_address),
                'telephone' => $this->phones->normalize((string) $order->customer_phone),
                'telephone2' => '',
            ],
            'Produit' => [
                'prix' => round($order->total_millimes / 1000, 3),
                'designation' => $this->providerText($this->designationFormatter->format($order)),
                'nombreArticle' => $totalQuantity,
                'commentaire' => '',
                'article' => $this->providerText($article),
                'nombreEchange' => $order->is_exchange ? (int) ($order->exchange_article_count ?? 0) : 0,
                'estFragile' => 'non',
                'ouvrirColis' => 'non',
            ],
        ];
    }

    private function providerText(string $value, string $fallback = ''): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
        $value = is_string($normalized) ? $normalized : $value;
        $value = preg_replace('/[\p{Cf}\x{FE0E}\x{FE0F}\x{20E3}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\p{Extended_Pictographic}\p{Emoji_Presentation}]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value !== '' ? mb_substr($value, 0, 500) : $fallback;
    }
}
